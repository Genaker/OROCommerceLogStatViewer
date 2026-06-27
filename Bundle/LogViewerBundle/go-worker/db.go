package main

import (
	"context"
	"fmt"
	"log"

	"github.com/jackc/pgx/v4/pgxpool"
)

// DBWriter is the interface for database operations.
// Implemented by PgWriter (PostgreSQL) and SqliteWriter (tests).
type DBWriter interface {
	EnsureTable(ctx context.Context) error
	UpsertBatch(ctx context.Context, entries []*GroupedEntry) (int, error)
	InsertBatch(ctx context.Context, entries []*GroupedEntry) (int, error)
	Close()
}

// PgWriter uses pgx connection pool for PostgreSQL.
type PgWriter struct {
	pool *pgxpool.Pool
}

func NewPgWriter(ctx context.Context, dsn string, maxConns int) (*PgWriter, error) {
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, fmt.Errorf("parse DSN: %w", err)
	}

	cfg.MaxConns = int32(maxConns + 1)
	cfg.MinConns = 1

	pool, err := pgxpool.ConnectConfig(ctx, cfg)
	if err != nil {
		return nil, fmt.Errorf("connect: %w", err)
	}

	if err := pool.Ping(ctx); err != nil {
		pool.Close()
		return nil, fmt.Errorf("ping: %w", err)
	}

	log.Printf("[db] connected (max_conns=%d)", cfg.MaxConns)
	return &PgWriter{pool: pool}, nil
}

func (d *PgWriter) Close() {
	d.pool.Close()
}

func (d *PgWriter) EnsureTable(ctx context.Context) error {
	_, err := d.pool.Exec(ctx, createTableSQL)
	if err != nil {
		return err
	}
	for _, idx := range createIndexSQL {
		if _, err := d.pool.Exec(ctx, idx); err != nil {
			return err
		}
	}
	return nil
}

func (d *PgWriter) UpsertBatch(ctx context.Context, entries []*GroupedEntry) (int, error) {
	tx, err := d.pool.Begin(ctx)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback(ctx)

	count := 0
	for _, e := range entries {
		_, err := tx.Exec(ctx, upsertSQL,
			e.Channel, e.Level, e.LevelName, e.Message,
			nullStr(e.Context), nullStr(e.Extra),
			e.LastSeen, nullStr(e.URL), nullStr(e.IP),
			e.MessageKey, e.Count, e.FirstSeen,
		)
		if err != nil {
			log.Printf("[db] upsert error: %v", err)
			continue
		}
		count++
	}

	if err := tx.Commit(ctx); err != nil {
		return 0, err
	}
	return count, nil
}

func (d *PgWriter) InsertBatch(ctx context.Context, entries []*GroupedEntry) (int, error) {
	tx, err := d.pool.Begin(ctx)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback(ctx)

	count := 0
	for _, e := range entries {
		_, err := tx.Exec(ctx, insertSQL,
			e.Channel, e.Level, e.LevelName, e.Message,
			nullStr(e.Context), nullStr(e.Extra),
			e.LastSeen, nullStr(e.URL), nullStr(e.IP),
			e.Count, e.FirstSeen,
		)
		if err != nil {
			log.Printf("[db] insert error: %v", err)
			continue
		}
		count++
	}

	if err := tx.Commit(ctx); err != nil {
		return 0, err
	}
	return count, nil
}

func nullStr(s string) interface{} {
	if s == "" {
		return nil
	}
	return s
}

// Shared SQL statements

const createTableSQL = `
CREATE TABLE IF NOT EXISTS genaker_log_entry (
	id               BIGSERIAL PRIMARY KEY,
	channel          VARCHAR(64)  NOT NULL,
	level            SMALLINT     NOT NULL,
	level_name       VARCHAR(20)  NOT NULL,
	message          TEXT         NOT NULL,
	context          JSONB,
	extra            JSONB,
	created_at       TIMESTAMP    NOT NULL,
	url              VARCHAR(2000),
	ip               VARCHAR(45),
	message_key      VARCHAR(64),
	occurrence_count INTEGER      NOT NULL DEFAULT 1,
	first_seen_at    TIMESTAMP
)`

var createIndexSQL = []string{
	"CREATE INDEX IF NOT EXISTS idx_log_entry_channel ON genaker_log_entry (channel)",
	"CREATE INDEX IF NOT EXISTS idx_log_entry_level ON genaker_log_entry (level)",
	"CREATE INDEX IF NOT EXISTS idx_log_entry_created ON genaker_log_entry (created_at)",
	"CREATE UNIQUE INDEX IF NOT EXISTS uniq_log_entry_message_key ON genaker_log_entry (message_key)",
}

const upsertSQL = `
INSERT INTO genaker_log_entry
	(channel, level, level_name, message, context, extra, created_at, url, ip, message_key, occurrence_count, first_seen_at)
VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb, $7, $8, $9, $10, $11, $12)
ON CONFLICT (message_key) DO UPDATE SET
	occurrence_count = genaker_log_entry.occurrence_count + $11,
	created_at       = $7,
	context          = $5::jsonb,
	extra            = $6::jsonb,
	url              = $8,
	ip               = $9`

const insertSQL = `
INSERT INTO genaker_log_entry
	(channel, level, level_name, message, context, extra, created_at, url, ip, occurrence_count, first_seen_at)
VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb, $7, $8, $9, $10, $11)`
