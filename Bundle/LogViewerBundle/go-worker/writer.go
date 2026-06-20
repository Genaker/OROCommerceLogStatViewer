package main

import (
	"context"
	"log"
	"sync/atomic"
	"time"
)

type BatchWriter struct {
	db  DBWriter
	cfg *Config
	// stats
	totalWritten atomic.Int64
	totalDropped atomic.Int64
}

func NewBatchWriter(db DBWriter, cfg *Config) *BatchWriter {
	return &BatchWriter{db: db, cfg: cfg}
}

func (w *BatchWriter) Run(ctx context.Context, in <-chan *LogEntry) {
	batch := make([]*LogEntry, 0, w.cfg.BatchSize)
	flushInterval := time.Duration(w.cfg.FlushIntervalMs) * time.Millisecond
	ticker := time.NewTicker(flushInterval)
	defer ticker.Stop()

	statsInterval := 30 * time.Second
	statsTicker := time.NewTicker(statsInterval)
	defer statsTicker.Stop()

	for {
		select {
		case <-ctx.Done():
			if len(batch) > 0 {
				flushCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
				w.flush(flushCtx, batch)
				cancel()
			}
			return

		case entry, ok := <-in:
			if !ok {
				if len(batch) > 0 {
					w.flush(ctx, batch)
				}
				return
			}
			batch = append(batch, entry)
			if len(batch) >= w.cfg.BatchSize {
				w.flush(ctx, batch)
				batch = make([]*LogEntry, 0, w.cfg.BatchSize)
			}

		case <-ticker.C:
			if len(batch) > 0 {
				w.flush(ctx, batch)
				batch = make([]*LogEntry, 0, w.cfg.BatchSize)
			}

		case <-statsTicker.C:
			written := w.totalWritten.Load()
			dropped := w.totalDropped.Load()
			if written > 0 || dropped > 0 {
				log.Printf("[writer] stats: %d written, %d dropped, %d pending",
					written, dropped, len(in))
			}
		}
	}
}

func (w *BatchWriter) flush(ctx context.Context, batch []*LogEntry) {
	if len(batch) == 0 {
		return
	}

	var written int
	var err error

	if w.cfg.GroupingEnabled {
		// Pre-aggregate in memory before hitting DB
		grouped := GroupBatch(batch, w.cfg.GroupingKeyLen)
		written, err = w.db.UpsertBatch(ctx, grouped)
		if err != nil {
			log.Printf("[writer] upsert flush error (%d entries -> %d grouped): %v",
				len(batch), len(grouped), err)
			w.totalDropped.Add(int64(len(batch)))
			return
		}
	} else {
		grouped := make([]*GroupedEntry, len(batch))
		for i, e := range batch {
			grouped[i] = &GroupedEntry{
				LogEntry:  *e,
				Count:     1,
				FirstSeen: e.CreatedAt,
				LastSeen:  e.CreatedAt,
			}
		}
		written, err = w.db.InsertBatch(ctx, grouped)
		if err != nil {
			log.Printf("[writer] insert flush error (%d entries): %v", len(batch), err)
			w.totalDropped.Add(int64(len(batch)))
			return
		}
	}

	w.totalWritten.Add(int64(written))
	dropped := len(batch) - written
	if dropped > 0 {
		w.totalDropped.Add(int64(dropped))
	}
}
