package main

import (
	"testing"
)

func TestParseLineStandard(t *testing.T) {
	line := `[2026-06-19T17:21:59.123456+00:00] app.WARNING: Weight limits unavailable from API {"error":"minWeight not available"} {"extra_key":"val"}`
	e := ParseLine(line)
	if e == nil {
		t.Fatal("expected entry, got nil")
	}
	assertEqual(t, "channel", e.Channel, "app")
	assertEqual(t, "level_name", e.LevelName, "WARNING")
	assertIntEqual(t, "level", e.Level, 300)
	assertEqual(t, "message", e.Message, "Weight limits unavailable from API")
	if e.Context == "" {
		t.Error("context should not be empty")
	}
	if e.Extra == "" {
		t.Error("extra should not be empty")
	}
}

func TestParseLineEmptyContext(t *testing.T) {
	line := `[2026-05-29T04:59:32.205175+00:00] app.INFO: DELETE FROM oro_entity_config [] []`
	e := ParseLine(line)
	if e == nil {
		t.Fatal("expected entry, got nil")
	}
	assertEqual(t, "level_name", e.LevelName, "INFO")
	assertEqual(t, "context", e.Context, "")
	assertEqual(t, "extra", e.Extra, "")
}

func TestParseLineNoJSON(t *testing.T) {
	line := `[2026-06-19T10:00:00.000000+00:00] request.ERROR: Something broke badly`
	e := ParseLine(line)
	if e == nil {
		t.Fatal("expected entry, got nil")
	}
	assertEqual(t, "message", e.Message, "Something broke badly")
	assertEqual(t, "channel", e.Channel, "request")
	assertIntEqual(t, "level", e.Level, 400)
}

func TestParseLineCritical(t *testing.T) {
	line := `[2026-05-29T05:00:57.056552+00:00] request.CRITICAL: Uncaught PHP Exception {"exception":"[object]"} {}`
	e := ParseLine(line)
	if e == nil {
		t.Fatal("expected entry, got nil")
	}
	assertEqual(t, "level_name", e.LevelName, "CRITICAL")
	assertIntEqual(t, "level", e.Level, 500)
	assertEqual(t, "message", e.Message, "Uncaught PHP Exception")
	if e.Context == "" {
		t.Error("context should not be empty")
	}
}

func TestParseLineInvalid(t *testing.T) {
	cases := []string{"", "   ", "not a log line", "random text with {json}", "[incomplete"}
	for _, c := range cases {
		if ParseLine(c) != nil {
			t.Errorf("expected nil for %q", c)
		}
	}
}

func TestParseLineNestedJSON(t *testing.T) {
	line := `[2026-06-19T10:00:00.000000+00:00] app.INFO: API call {"response":{"data":[{"id":1}]}} {}`
	e := ParseLine(line)
	if e == nil {
		t.Fatal("expected entry, got nil")
	}
	assertEqual(t, "message", e.Message, "API call")
	if e.Context == "" {
		t.Error("context should contain nested JSON")
	}
}

func TestIsLevelAtOrAbove(t *testing.T) {
	tests := []struct {
		entry, min string
		want       bool
	}{
		{"DEBUG", "WARNING", false},
		{"INFO", "WARNING", false},
		{"NOTICE", "WARNING", false},
		{"WARNING", "WARNING", true},
		{"ERROR", "WARNING", true},
		{"CRITICAL", "DEBUG", true},
		{"DEBUG", "DEBUG", true},
		{"EMERGENCY", "ERROR", true},
	}
	for _, tt := range tests {
		got := IsLevelAtOrAbove(tt.entry, tt.min)
		if got != tt.want {
			t.Errorf("IsLevelAtOrAbove(%q, %q) = %v, want %v", tt.entry, tt.min, got, tt.want)
		}
	}
}

func BenchmarkParseLine(b *testing.B) {
	line := `[2026-06-19T17:21:59.123456+00:00] app.WARNING: Weight limits unavailable from API {"error":"minWeight not available from API using default."} {}`
	for i := 0; i < b.N; i++ {
		ParseLine(line)
	}
}

func assertEqual(t *testing.T, name, got, want string) {
	t.Helper()
	if got != want {
		t.Errorf("%s = %q, want %q", name, got, want)
	}
}

func assertIntEqual(t *testing.T, name string, got, want int) {
	t.Helper()
	if got != want {
		t.Errorf("%s = %d, want %d", name, got, want)
	}
}
