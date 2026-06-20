package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"
)

func main() {
	configPath := flag.String("config", "config.yaml", "Path to config file")
	flag.Parse()

	cfg, err := LoadConfig(*configPath)
	if err != nil {
		log.Fatalf("Failed to load config: %v", err)
	}

	dsn, err := cfg.ResolveDSN()
	if err != nil {
		log.Fatalf("Failed to resolve DB DSN: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	db, err := NewPgWriter(ctx, dsn, cfg.Workers)
	if err != nil {
		log.Fatalf("Failed to connect to database: %v", err)
	}
	defer db.Close()

	if err := db.EnsureTable(ctx); err != nil {
		log.Fatalf("Failed to ensure genaker_log_entry table: %v", err)
	}

	entryCh := make(chan *LogEntry, cfg.BatchSize*10)

	var wg sync.WaitGroup

	writer := NewBatchWriter(db, cfg)
	wg.Add(1)
	go func() {
		defer wg.Done()
		writer.Run(ctx, entryCh)
	}()

	for _, logFile := range cfg.LogFiles {
		matches, err := resolveGlob(logFile)
		if err != nil {
			log.Printf("Warning: glob error for %q: %v", logFile, err)
			continue
		}
		for _, path := range matches {
			wg.Add(1)
			go func(p string) {
				defer wg.Done()
				tailer := NewTailer(p, cfg)
				tailer.Run(ctx, entryCh)
			}(path)
		}
	}

	log.Printf("Log worker started: watching %d file pattern(s), min_level=%s, batch=%d, grouping=%v",
		len(cfg.LogFiles), cfg.MinLevel, cfg.BatchSize, cfg.GroupingEnabled)

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	sig := <-sigCh
	fmt.Printf("\nReceived %v, shutting down...\n", sig)
	cancel()

	done := make(chan struct{})
	go func() {
		wg.Wait()
		close(done)
	}()

	select {
	case <-done:
		log.Println("Shutdown complete")
	case <-time.After(10 * time.Second):
		log.Println("Shutdown timeout, exiting")
	}
}
