package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// newTestDB creates an in-memory SQLite writer for testing.
func newTestDB(t *testing.T) *SqliteWriter {
	t.Helper()
	db, err := NewSqliteWriter(":memory:")
	if err != nil {
		t.Fatalf("NewSqliteWriter: %v", err)
	}
	if err := db.EnsureTable(context.Background()); err != nil {
		t.Fatalf("EnsureTable: %v", err)
	}
	return db
}

func countRows(t *testing.T, db *SqliteWriter) int {
	t.Helper()
	rows, err := db.QueryRows(context.Background(), "SELECT COUNT(*) as cnt FROM genaker_log_entry")
	if err != nil {
		t.Fatal(err)
	}
	return int(rows[0]["cnt"].(int64))
}

func fetchAll(t *testing.T, db *SqliteWriter) []map[string]interface{} {
	t.Helper()
	rows, err := db.QueryRows(context.Background(), "SELECT * FROM genaker_log_entry ORDER BY id ASC")
	if err != nil {
		t.Fatal(err)
	}
	return rows
}

// --- Integration: Insert without grouping ---

func TestIntegrationInsertBatch(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	entries := []*GroupedEntry{
		makeGrouped("app", 400, "ERROR", "Error one", 1),
		makeGrouped("app", 300, "WARNING", "Warning two", 1),
		makeGrouped("security", 400, "ERROR", "Auth failed", 1),
	}

	written, err := db.InsertBatch(ctx, entries)
	if err != nil {
		t.Fatal(err)
	}
	if written != 3 {
		t.Errorf("written = %d, want 3", written)
	}
	if countRows(t, db) != 3 {
		t.Errorf("rows = %d, want 3", countRows(t, db))
	}
}

// --- Integration: Upsert with grouping ---

func TestIntegrationUpsertMergesDuplicates(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	// First batch: 3 identical entries grouped into 1
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db.example.com", CreatedAt: time.Now()},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db.example.com", CreatedAt: time.Now().Add(time.Second)},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db.example.com", CreatedAt: time.Now().Add(2 * time.Second)},
	}
	grouped := GroupBatch(entries, 30)

	written, err := db.UpsertBatch(ctx, grouped)
	if err != nil {
		t.Fatal(err)
	}
	if written != 1 {
		t.Errorf("written = %d, want 1", written)
	}
	if countRows(t, db) != 1 {
		t.Errorf("rows = %d, want 1", countRows(t, db))
	}

	rows := fetchAll(t, db)
	count := rows[0]["occurrence_count"].(int64)
	if count != 3 {
		t.Errorf("occurrence_count = %d, want 3", count)
	}
}

func TestIntegrationUpsertAccumulatesAcrossBatches(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	msg := "Repeated error across batches"
	now := time.Now()

	// Batch 1: 5 identical
	batch1 := make([]*LogEntry, 5)
	for i := range batch1 {
		batch1[i] = &LogEntry{Channel: "app", Level: 400, LevelName: "ERROR", Message: msg, CreatedAt: now.Add(time.Duration(i) * time.Second)}
	}
	g1 := GroupBatch(batch1, 30)
	db.UpsertBatch(ctx, g1)

	// Batch 2: 3 more identical
	batch2 := make([]*LogEntry, 3)
	for i := range batch2 {
		batch2[i] = &LogEntry{Channel: "app", Level: 400, LevelName: "ERROR", Message: msg, CreatedAt: now.Add(time.Duration(i+10) * time.Second)}
	}
	g2 := GroupBatch(batch2, 30)
	db.UpsertBatch(ctx, g2)

	if countRows(t, db) != 1 {
		t.Fatalf("expected 1 row, got %d", countRows(t, db))
	}

	rows := fetchAll(t, db)
	count := rows[0]["occurrence_count"].(int64)
	if count != 8 {
		t.Errorf("occurrence_count = %d, want 8 (5+3)", count)
	}
}

func TestIntegrationUpsertKeepsDifferentEntriesSeparate(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Error A", CreatedAt: time.Now()},
		{Channel: "app", Level: 300, LevelName: "WARNING", Message: "Warning B", CreatedAt: time.Now()},
		{Channel: "security", Level: 400, LevelName: "ERROR", Message: "Auth C", CreatedAt: time.Now()},
	}
	grouped := GroupBatch(entries, 30)
	db.UpsertBatch(ctx, grouped)

	if countRows(t, db) != 3 {
		t.Errorf("expected 3 separate rows, got %d", countRows(t, db))
	}
}

func TestIntegrationUpsertUpdatesContext(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	now := time.Now()
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "API timeout", Context: `{"attempt":1}`, CreatedAt: now},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "API timeout", Context: `{"attempt":2}`, CreatedAt: now.Add(time.Second)},
	}
	grouped := GroupBatch(entries, 30)
	db.UpsertBatch(ctx, grouped)

	rows := fetchAll(t, db)
	ctx_val := rows[0]["context"].(string)
	if ctx_val != `{"attempt":2}` {
		t.Errorf("context should be latest, got %q", ctx_val)
	}
}

// --- Integration: Full pipeline (parse -> group -> upsert) ---

func TestIntegrationFullPipeline(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	lines := []string{
		`[2026-06-19T10:00:00.000000+00:00] app.ERROR: Connection refused {"host":"db1"} {}`,
		`[2026-06-19T10:00:01.000000+00:00] app.ERROR: Connection refused {"host":"db2"} {}`,
		`[2026-06-19T10:00:02.000000+00:00] app.ERROR: Connection refused {"host":"db3"} {}`,
		`[2026-06-19T10:00:03.000000+00:00] app.WARNING: Slow query detected {"ms":1200} {}`,
		`[2026-06-19T10:00:04.000000+00:00] app.WARNING: Slow query detected {"ms":2400} {}`,
		`[2026-06-19T10:00:05.000000+00:00] app.INFO: Request completed {} {}`,
	}

	var entries []*LogEntry
	for _, line := range lines {
		e := ParseLine(line)
		if e != nil && IsLevelAtOrAbove(e.LevelName, "WARNING") {
			entries = append(entries, e)
		}
	}

	// Should have filtered out INFO
	if len(entries) != 5 {
		t.Fatalf("expected 5 entries after filter, got %d", len(entries))
	}

	grouped := GroupBatch(entries, 30)

	// "Connection refused" x3 + "Slow query detected" x2 = 2 groups
	if len(grouped) != 2 {
		t.Fatalf("expected 2 groups, got %d", len(grouped))
	}
	if grouped[0].Count != 3 {
		t.Errorf("first group count = %d, want 3", grouped[0].Count)
	}
	if grouped[1].Count != 2 {
		t.Errorf("second group count = %d, want 2", grouped[1].Count)
	}

	// Latest context should win
	if grouped[0].Context != `{"host":"db3"}` {
		t.Errorf("context = %q, want latest", grouped[0].Context)
	}
	if grouped[1].Context != `{"ms":2400}` {
		t.Errorf("context = %q, want latest", grouped[1].Context)
	}

	written, err := db.UpsertBatch(ctx, grouped)
	if err != nil {
		t.Fatal(err)
	}
	if written != 2 {
		t.Errorf("written = %d, want 2", written)
	}
	if countRows(t, db) != 2 {
		t.Errorf("rows = %d, want 2", countRows(t, db))
	}
}

// --- Integration: File parsing + DB ---

func TestIntegrationParseFileAndIngest(t *testing.T) {
	db := newTestDB(t)
	defer db.Close()
	ctx := context.Background()

	// Create a temp log file
	dir := t.TempDir()
	logPath := filepath.Join(dir, "test.log")

	var content string
	for i := 0; i < 100; i++ {
		content += fmt.Sprintf("[2026-06-19T10:%02d:%02d.000000+00:00] app.ERROR: Connection refused to host database server {\"retry\":%d} {}\n", i/60, i%60, i)
	}
	os.WriteFile(logPath, []byte(content), 0644)

	// Parse all lines
	lines := make([]*LogEntry, 0)
	f, _ := os.Open(logPath)
	defer f.Close()

	scanner := make([]byte, 0, 4096)
	_ = scanner
	// Simple line reader
	data, _ := os.ReadFile(logPath)
	for _, line := range splitLines(string(data)) {
		if e := ParseLine(line); e != nil {
			lines = append(lines, e)
		}
	}

	if len(lines) != 100 {
		t.Fatalf("parsed %d lines, want 100", len(lines))
	}

	// Group: all 100 share same 30-char prefix "DB connection failed attempt "
	grouped := GroupBatch(lines, 30)
	if len(grouped) != 1 {
		t.Fatalf("expected 1 group from 100 identical-prefix entries, got %d", len(grouped))
	}
	if grouped[0].Count != 100 {
		t.Errorf("count = %d, want 100", grouped[0].Count)
	}

	written, err := db.UpsertBatch(ctx, grouped)
	if err != nil {
		t.Fatal(err)
	}
	if written != 1 {
		t.Errorf("written = %d, want 1", written)
	}

	rows := fetchAll(t, db)
	if rows[0]["occurrence_count"].(int64) != 100 {
		t.Errorf("occurrence_count = %d, want 100", rows[0]["occurrence_count"])
	}
}

// --- Helpers ---

func makeGrouped(ch string, level int, levelName, msg string, count int) *GroupedEntry {
	now := time.Now()
	e := &LogEntry{Channel: ch, Level: level, LevelName: levelName, Message: msg, CreatedAt: now}
	return &GroupedEntry{
		LogEntry:   *e,
		MessageKey: e.MessageKey(30),
		Count:      count,
		FirstSeen:  now,
		LastSeen:   now,
	}
}

func splitLines(s string) []string {
	var lines []string
	start := 0
	for i := 0; i < len(s); i++ {
		if s[i] == '\n' {
			line := s[start:i]
			if len(line) > 0 {
				lines = append(lines, line)
			}
			start = i + 1
		}
	}
	if start < len(s) {
		lines = append(lines, s[start:])
	}
	return lines
}
