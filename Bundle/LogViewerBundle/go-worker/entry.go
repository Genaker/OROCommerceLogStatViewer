package main

import (
	"crypto/md5"
	"fmt"
	"time"
)

var levelMap = map[string]int{
	"DEBUG":     100,
	"INFO":      200,
	"NOTICE":    250,
	"WARNING":   300,
	"ERROR":     400,
	"CRITICAL":  500,
	"ALERT":     550,
	"EMERGENCY": 600,
}

type LogEntry struct {
	Channel   string
	Level     int
	LevelName string
	Message   string
	Context   string
	Extra     string
	CreatedAt time.Time
	URL       string
	IP        string
}

func (e *LogEntry) MessageKey(keyLen int) string {
	prefix := e.Message
	if len(prefix) > keyLen {
		prefix = prefix[:keyLen]
	}
	src := fmt.Sprintf("%s|%d|%s", e.Channel, e.Level, prefix)
	return fmt.Sprintf("%x", md5.Sum([]byte(src)))
}

func LevelValue(name string) int {
	if v, ok := levelMap[name]; ok {
		return v
	}
	return 0
}

func IsLevelAtOrAbove(entryLevel, minLevel string) bool {
	return LevelValue(entryLevel) >= LevelValue(minLevel)
}

// GroupedEntry is a pre-aggregated entry for batch upsert.
// Multiple raw LogEntries with the same message key are merged
// into one GroupedEntry before hitting the DB.
type GroupedEntry struct {
	LogEntry
	MessageKey string
	Count      int
	FirstSeen  time.Time
	LastSeen   time.Time
}

// GroupBatch takes a slice of raw entries and returns deduplicated
// GroupedEntries. Entries with the same message key are merged:
// count is summed, latest context/url/ip/timestamp wins,
// earliest timestamp becomes FirstSeen.
func GroupBatch(entries []*LogEntry, keyLen int) []*GroupedEntry {
	index := make(map[string]*GroupedEntry, len(entries))
	var order []string

	for _, e := range entries {
		key := e.MessageKey(keyLen)

		if g, ok := index[key]; ok {
			g.Count++
			if e.CreatedAt.After(g.LastSeen) {
				g.LastSeen = e.CreatedAt
				g.Context = e.Context
				g.Extra = e.Extra
				g.URL = e.URL
				g.IP = e.IP
				g.Message = e.Message
			}
			if e.CreatedAt.Before(g.FirstSeen) {
				g.FirstSeen = e.CreatedAt
			}
		} else {
			g := &GroupedEntry{
				LogEntry:   *e,
				MessageKey: key,
				Count:      1,
				FirstSeen:  e.CreatedAt,
				LastSeen:   e.CreatedAt,
			}
			index[key] = g
			order = append(order, key)
		}
	}

	result := make([]*GroupedEntry, 0, len(order))
	for _, key := range order {
		result = append(result, index[key])
	}
	return result
}
