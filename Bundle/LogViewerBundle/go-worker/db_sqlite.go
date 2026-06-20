package main

import (
	"context"
	"database/sql"
	"fmt"
	"log"

	_ "github.com/mattn/go-sqlite3"
)

// SqliteWriter implements DBWriter using SQLite for integration tests.
type SqliteWriter struct {
	db *sql.DB
}

func NewSqliteWriter(dsn string) (*SqliteWriter, error) {
	db, err := sql.Open("sqlite3", dsn)
	if err != nil {
		return nil, err
	}
	return &SqliteWriter{db: db}, nil
}

func (s *SqliteWriter) Close() {
	s.db.Close()
}

func (s *SqliteWriter) EnsureTable(ctx context.Context) error {
	_, err := s.db.ExecContext(ctx, `
		CREATE TABLE IF NOT EXISTS genaker_log_entry (
			id               INTEGER PRIMARY KEY AUTOINCREMENT,
			channel          VARCHAR(64)  NOT NULL,
			level            SMALLINT     NOT NULL,
			level_name       VARCHAR(20)  NOT NULL,
			message          TEXT         NOT NULL,
			context          TEXT,
			extra            TEXT,
			created_at       DATETIME     NOT NULL,
			url              VARCHAR(2000),
			ip               VARCHAR(45),
			message_key      VARCHAR(64)  UNIQUE,
			occurrence_count INTEGER      NOT NULL DEFAULT 1,
			first_seen_at    DATETIME
		)
	`)
	return err
}

func (s *SqliteWriter) UpsertBatch(ctx context.Context, entries []*GroupedEntry) (int, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	count := 0
	for _, e := range entries {
		// SQLite: INSERT OR REPLACE loses the old row. Use a two-step approach.
		var existingCount int
		var firstSeen string
		err := tx.QueryRowContext(ctx,
			"SELECT occurrence_count, first_seen_at FROM genaker_log_entry WHERE message_key = ?",
			e.MessageKey,
		).Scan(&existingCount, &firstSeen)

		if err == sql.ErrNoRows {
			_, err = tx.ExecContext(ctx,
				`INSERT INTO genaker_log_entry
					(channel, level, level_name, message, context, extra, created_at, url, ip, message_key, occurrence_count, first_seen_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
				e.Channel, e.Level, e.LevelName, e.Message,
				nullStr(e.Context), nullStr(e.Extra),
				e.LastSeen.Format("2006-01-02 15:04:05"), nullStr(e.URL), nullStr(e.IP),
				e.MessageKey, e.Count, e.FirstSeen.Format("2006-01-02 15:04:05"),
			)
		} else if err == nil {
			_, err = tx.ExecContext(ctx,
				`UPDATE genaker_log_entry SET
					occurrence_count = occurrence_count + ?,
					created_at = ?,
					context = ?,
					extra = ?,
					url = ?,
					ip = ?
				 WHERE message_key = ?`,
				e.Count,
				e.LastSeen.Format("2006-01-02 15:04:05"),
				nullStr(e.Context), nullStr(e.Extra),
				nullStr(e.URL), nullStr(e.IP),
				e.MessageKey,
			)
		}

		if err != nil {
			log.Printf("[sqlite] upsert error: %v", err)
			continue
		}
		count++
	}

	if err := tx.Commit(); err != nil {
		return 0, err
	}
	return count, nil
}

func (s *SqliteWriter) InsertBatch(ctx context.Context, entries []*GroupedEntry) (int, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	count := 0
	for _, e := range entries {
		_, err := tx.ExecContext(ctx,
			`INSERT INTO genaker_log_entry
				(channel, level, level_name, message, context, extra, created_at, url, ip, occurrence_count, first_seen_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			e.Channel, e.Level, e.LevelName, e.Message,
			nullStr(e.Context), nullStr(e.Extra),
			e.LastSeen.Format("2006-01-02 15:04:05"), nullStr(e.URL), nullStr(e.IP),
			e.Count, e.FirstSeen.Format("2006-01-02 15:04:05"),
		)
		if err != nil {
			log.Printf("[sqlite] insert error: %v", err)
			continue
		}
		count++
	}

	if err := tx.Commit(); err != nil {
		return 0, err
	}
	return count, nil
}

// QueryRows is a test helper to read rows from SQLite.
func (s *SqliteWriter) QueryRows(ctx context.Context, query string, args ...interface{}) ([]map[string]interface{}, error) {
	rows, err := s.db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	cols, _ := rows.Columns()
	var result []map[string]interface{}

	for rows.Next() {
		vals := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil, fmt.Errorf("scan: %w", err)
		}
		row := make(map[string]interface{})
		for i, col := range cols {
			row[col] = vals[i]
		}
		result = append(result, row)
	}
	return result, rows.Err()
}
