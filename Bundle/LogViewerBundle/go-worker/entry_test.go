package main

import (
	"testing"
	"time"
)

func TestMessageKeySamePrefix(t *testing.T) {
	e1 := &LogEntry{Channel: "app", Level: 400, Message: "Connection refused to host db.example.com"}
	e2 := &LogEntry{Channel: "app", Level: 400, Message: "Connection refused to host db.other.com"}

	if e1.MessageKey(30) != e2.MessageKey(30) {
		t.Error("same 30-char prefix should produce same key")
	}
}

func TestMessageKeyDifferentLevel(t *testing.T) {
	e1 := &LogEntry{Channel: "app", Level: 400, Message: "Connection refused"}
	e2 := &LogEntry{Channel: "app", Level: 300, Message: "Connection refused"}

	if e1.MessageKey(30) == e2.MessageKey(30) {
		t.Error("different levels should produce different keys")
	}
}

func TestMessageKeyDifferentChannel(t *testing.T) {
	e1 := &LogEntry{Channel: "app", Level: 400, Message: "Same message"}
	e2 := &LogEntry{Channel: "security", Level: 400, Message: "Same message"}

	if e1.MessageKey(30) == e2.MessageKey(30) {
		t.Error("different channels should produce different keys")
	}
}

func TestMessageKeyShortMessage(t *testing.T) {
	e := &LogEntry{Channel: "app", Level: 400, Message: "short"}
	key := e.MessageKey(30)
	if key == "" {
		t.Error("key should not be empty for short messages")
	}
}

func TestGroupBatchMergesDuplicates(t *testing.T) {
	now := time.Now()
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db", CreatedAt: now},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db", CreatedAt: now.Add(time.Second)},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db", CreatedAt: now.Add(2 * time.Second)},
	}

	grouped := GroupBatch(entries, 30)

	if len(grouped) != 1 {
		t.Fatalf("expected 1 grouped entry, got %d", len(grouped))
	}
	if grouped[0].Count != 3 {
		t.Errorf("count = %d, want 3", grouped[0].Count)
	}
	if !grouped[0].FirstSeen.Equal(now) {
		t.Errorf("first_seen should be earliest timestamp")
	}
	if !grouped[0].LastSeen.Equal(now.Add(2 * time.Second)) {
		t.Errorf("last_seen should be latest timestamp")
	}
}

func TestGroupBatchKeepsDifferentEntries(t *testing.T) {
	now := time.Now()
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Error A happened", CreatedAt: now},
		{Channel: "app", Level: 300, LevelName: "WARNING", Message: "Warning B happened", CreatedAt: now},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Error C happened", CreatedAt: now},
	}

	grouped := GroupBatch(entries, 30)
	if len(grouped) != 3 {
		t.Fatalf("expected 3 grouped entries, got %d", len(grouped))
	}
}

func TestGroupBatchPreservesOrder(t *testing.T) {
	now := time.Now()
	// Same 30-char prefix: "Connection refused to host db."
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db.example.com", CreatedAt: now},
		{Channel: "app", Level: 300, LevelName: "WARNING", Message: "Something else entirely here", CreatedAt: now},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "Connection refused to host db.other.com", CreatedAt: now.Add(time.Second)},
	}

	grouped := GroupBatch(entries, 30)
	if len(grouped) != 2 {
		t.Fatalf("expected 2 grouped entries, got %d", len(grouped))
	}
	if grouped[0].Count != 2 {
		t.Errorf("first group count = %d, want 2", grouped[0].Count)
	}
	if grouped[1].Count != 1 {
		t.Errorf("second group count = %d, want 1", grouped[1].Count)
	}
}

func TestGroupBatchUpdatesLatestContext(t *testing.T) {
	now := time.Now()
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "API timeout", Context: `{"attempt":1}`, CreatedAt: now},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "API timeout", Context: `{"attempt":2}`, CreatedAt: now.Add(time.Second)},
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "API timeout", Context: `{"attempt":3}`, CreatedAt: now.Add(2 * time.Second)},
	}

	grouped := GroupBatch(entries, 30)
	if grouped[0].Context != `{"attempt":3}` {
		t.Errorf("context should be latest, got %q", grouped[0].Context)
	}
}

func TestGroupBatchEmpty(t *testing.T) {
	grouped := GroupBatch(nil, 30)
	if len(grouped) != 0 {
		t.Errorf("expected 0, got %d", len(grouped))
	}
}

func TestGroupBatchSingleEntry(t *testing.T) {
	entries := []*LogEntry{
		{Channel: "app", Level: 400, LevelName: "ERROR", Message: "solo", CreatedAt: time.Now()},
	}
	grouped := GroupBatch(entries, 30)
	if len(grouped) != 1 || grouped[0].Count != 1 {
		t.Error("single entry should produce count=1")
	}
}

func TestGroupBatchLargeVolume(t *testing.T) {
	now := time.Now()
	entries := make([]*LogEntry, 1000)
	for i := 0; i < 1000; i++ {
		entries[i] = &LogEntry{
			Channel:   "app",
			Level:     400,
			LevelName: "ERROR",
			Message:   "Same repeated error in production",
			CreatedAt: now.Add(time.Duration(i) * time.Millisecond),
		}
	}

	grouped := GroupBatch(entries, 30)
	if len(grouped) != 1 {
		t.Fatalf("1000 identical entries should group into 1, got %d", len(grouped))
	}
	if grouped[0].Count != 1000 {
		t.Errorf("count = %d, want 1000", grouped[0].Count)
	}
}

func BenchmarkGroupBatch(b *testing.B) {
	now := time.Now()
	entries := make([]*LogEntry, 100)
	for i := 0; i < 100; i++ {
		entries[i] = &LogEntry{
			Channel:   "app",
			Level:     400,
			LevelName: "ERROR",
			Message:   "Repeated error message for benchmarking",
			Context:   `{"attempt":1}`,
			CreatedAt: now.Add(time.Duration(i) * time.Millisecond),
		}
	}
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		GroupBatch(entries, 30)
	}
}
