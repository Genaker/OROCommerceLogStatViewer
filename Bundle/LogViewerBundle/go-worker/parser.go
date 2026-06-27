package main

import (
	"regexp"
	"strings"
	"time"
)

// Monolog format: [2026-05-29T04:59:32.205175+00:00] app.INFO: message {"context":"json"} {"extra":"json"}
var monologRe = regexp.MustCompile(
	`^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+[^\]]*)\]\s+` + // timestamp
		`(\w+)\.(\w+):\s+` + // channel.LEVEL
		`(.*)$`, // rest (message + context + extra)
)

func ParseLine(line string) *LogEntry {
	line = strings.TrimSpace(line)
	if line == "" {
		return nil
	}

	m := monologRe.FindStringSubmatch(line)
	if m == nil {
		return nil
	}

	ts, err := time.Parse(time.RFC3339Nano, m[1])
	if err != nil {
		ts = time.Now()
	}

	channel := m[2]
	levelName := strings.ToUpper(m[3])
	rest := m[4]

	message, context, extra := parseMessageAndJSON(rest)

	return &LogEntry{
		Channel:   truncate(channel, 64),
		Level:     LevelValue(levelName),
		LevelName: levelName,
		Message:   truncate(message, 65535),
		Context:   context,
		Extra:     extra,
		CreatedAt: ts,
	}
}

// parseMessageAndJSON splits "message text {json1} {json2}" into parts.
// Monolog appends context and extra as JSON after the message.
func parseMessageAndJSON(rest string) (message, context, extra string) {
	// Find JSON objects from the end
	rest = strings.TrimSpace(rest)

	// Try to find two JSON objects at the end: {context} {extra}
	var jsons []string
	remaining := rest

	for i := 0; i < 2; i++ {
		remaining = strings.TrimRight(remaining, " ")
		if !strings.HasSuffix(remaining, "}") {
			break
		}
		jsonStr, before := extractLastJSON(remaining)
		if jsonStr == "" {
			break
		}
		jsons = append([]string{jsonStr}, jsons...)
		remaining = before
	}

	message = strings.TrimSpace(remaining)

	switch len(jsons) {
	case 2:
		context = nullIfEmpty(jsons[0])
		extra = nullIfEmpty(jsons[1])
	case 1:
		context = nullIfEmpty(jsons[0])
	}

	return
}

// extractLastJSON finds the last balanced {} block from the end of s.
func extractLastJSON(s string) (json, before string) {
	end := len(s) - 1
	if end < 0 || s[end] != '}' {
		return "", s
	}

	depth := 0
	inString := false
	escaped := false

	for i := end; i >= 0; i-- {
		c := s[i]

		if escaped {
			escaped = false
			continue
		}

		if c == '\\' && inString {
			escaped = true
			continue
		}

		if c == '"' {
			inString = !inString
			continue
		}

		if inString {
			continue
		}

		if c == '}' {
			depth++
		} else if c == '{' {
			depth--
			if depth == 0 {
				return s[i : end+1], strings.TrimSpace(s[:i])
			}
		}
	}

	return "", s
}

func nullIfEmpty(s string) string {
	s = strings.TrimSpace(s)
	if s == "" || s == "[]" || s == "{}" {
		return ""
	}
	return s
}

func truncate(s string, maxLen int) string {
	if len(s) <= maxLen {
		return s
	}
	return s[:maxLen]
}
