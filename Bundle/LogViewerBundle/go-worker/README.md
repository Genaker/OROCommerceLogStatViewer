# OroCommerce Log Worker (Go)

High-performance Go worker that tails Monolog log files and ingests entries into the `genaker_log_entry` PostgreSQL table — the same table used by the Database Log Viewer in the admin UI.

## Why Go?

PHP's Monolog `DatabaseLogHandler` writes during the request lifecycle (deferred to `kernel.terminate`). This Go worker runs as a separate process that tails log files directly:

- **No PHP overhead** — doesn't consume PHP-FPM worker slots
- **Works with existing logs** — ingests historical log files, not just new entries
- **Survives PHP crashes** — runs independently; captures logs even when PHP segfaults
- **In-memory batch grouping** — deduplicates entries before they hit the DB (e.g., 1000 identical log lines → 1 upsert with `count=1000`)
- **Batch inserts** — groups entries into configurable batches in a single transaction
- **Handles log rotation** — automatically re-opens files after rotation
- **Oro config auto-discovery** — reads DB connection from `.env-app` files following Symfony/Oro precedence

## Features

| Feature | Description |
|---|---|
| Monolog parser | Regex-based parser for standard Monolog format: `[timestamp] channel.LEVEL: message {context} {extra}` |
| In-memory grouping | Entries with same `channel + level + message prefix` are merged in the batch before DB write. Only count + latest timestamp are upserted — no duplicate rows. |
| Batch DB writes | Configurable batch size (default: 100) + timer flush (default: 2s). All rows in a single transaction. |
| PostgreSQL upsert | `INSERT ON CONFLICT DO UPDATE SET occurrence_count = occurrence_count + N` — atomic, no race conditions |
| Level filtering | Only ingests entries at or above `min_level` (default: WARNING) |
| Channel filtering | (via DB-side config) |
| Glob patterns | Watch multiple files: `/var/logs/*.log` |
| Oro config discovery | Auto-detects DB DSN from: env var → `.env-app.local` → `.env-app.$ORO_ENV.local` → `.env-app.$ORO_ENV` → `.env-app` |
| DSN normalization | Strips Oro-specific params (`charset`, `serverVersion`) and converts `postgres://` → `postgresql://` for pgx |
| Connection pool | pgx pool with configurable max connections |
| Goroutine per file | Concurrent tailing with buffered channel to writer |
| Graceful shutdown | SIGINT/SIGTERM → flush remaining buffer with 5s timeout → exit |
| Docker ready | Multi-stage Dockerfile, ~15 MB final image |
| SQLite test suite | Integration tests use in-memory SQLite — no PostgreSQL needed to run tests |

## Quick Start

```bash
cd src/Genaker/Bundle/LogViewerBundle/go-worker

# Build
go build -o oro-log-worker .

# Run (auto-detects DB from .env-app)
./oro-log-worker -config config.yaml

# Or with explicit DSN
ORO_DB_URL="postgres://user:pass@localhost:5432/oro_db" ./oro-log-worker
```

## Configuration

```yaml
# DB connection (auto-detected from .env-app or ORO_DB_URL env var)
# db_dsn: "postgres://user:pass@host:5432/dbname"
oro_env_file: "/oro-ee/.env-app"
oro_project_root: "/oro-ee"

# Log files to watch (glob patterns supported)
log_files:
  - "/oro-ee/var/logs/dev.log"
  - "/oro-ee/var/logs/prod.log"

# Minimum log level to ingest
min_level: "WARNING"

# Batch insert size
batch_size: 100

# Flush interval (ms) — flush even if batch not full
flush_interval_ms: 2000

# In-memory grouping before DB write
grouping_enabled: true
grouping_key_length: 30

# Tail from end (true) or read entire file (false)
tail_from_end: true

# DB writer goroutines
workers: 2
```

### DB Connection Discovery

The worker discovers the PostgreSQL DSN in this priority order:

1. `db_dsn` in config.yaml (explicit, highest priority)
2. `ORO_DB_URL` environment variable
3. `oro_env_file` path (explicit file)
4. `.env-app.local` in `oro_project_root`
5. `.env-app.$ORO_ENV.local`
6. `.env-app.$ORO_ENV`
7. `.env-app` (lowest priority)

This matches Symfony/Oro's own `.env` file precedence. The DSN is automatically normalized: `postgres://` → `postgresql://`, and Oro-specific query params (`charset`, `serverVersion`) are stripped.

## Architecture

```
┌──────────────┐     ┌──────────────┐
│  dev.log     │────>│   Tailer     │
└──────────────┘     │  (goroutine  │──── parse ──── level filter ────┐
┌──────────────┐     │   per file)  │                                 │
│  prod.log    │────>│              │                                 │
└──────────────┘     └──────────────┘                                 │
                                                                      ▼
                                                           ┌──────────────────┐
                                                           │   Entry Channel  │
                                                           │  (buffered 10x)  │
                                                           └────────┬─────────┘
                                                                    │
                                                                    ▼
                                                           ┌──────────────────┐
                                                           │  Batch Writer    │
                                                           │  1. Collect N    │
                                                           │  2. GroupBatch() │ ← in-memory dedup
                                                           │  3. Flush on     │
                                                           │     batch full   │
                                                           │     OR timer     │
                                                           └────────┬─────────┘
                                                                    │
                                                                    ▼
                                                           ┌──────────────────┐
                                                           │  PostgreSQL      │
                                                           │  INSERT ON       │
                                                           │  CONFLICT DO     │
                                                           │  UPDATE count+=N │
                                                           └──────────────────┘
```

### In-Memory Batch Grouping

Before hitting the database, `GroupBatch()` deduplicates entries in memory:

```
Raw batch (100 entries):
  "Connection refused" x 47
  "Slow query"         x 30
  "Auth failed"        x 23

After GroupBatch():
  3 GroupedEntries:
    {key: "abc123", count: 47, message: "Connection refused", context: <latest>}
    {key: "def456", count: 30, message: "Slow query",         context: <latest>}
    {key: "ghi789", count: 23, message: "Auth failed",        context: <latest>}

DB upsert: 3 rows instead of 100
```

Each `GroupedEntry` carries:
- `Count` — how many raw entries were merged
- `FirstSeen` — earliest timestamp in the group
- `LastSeen` — latest timestamp (becomes `created_at`)
- Latest `context`, `url`, `ip` (most recent is most useful)

The DB upsert adds `Count` to the existing `occurrence_count`: `SET occurrence_count = occurrence_count + $count`

## Docker

```bash
# Build image (~15 MB)
docker build -t oro-log-worker .

# Run
docker run -d \
  -v /oro-ee/var/logs:/logs:ro \
  -e ORO_DB_URL="postgres://user:pass@db:5432/oro_db" \
  oro-log-worker

# Docker Compose
```

```yaml
log-worker:
  build: src/Genaker/Bundle/LogViewerBundle/go-worker
  volumes:
    - ./var/logs:/oro-ee/var/logs:ro
    - ./.env-app:/oro-ee/.env-app:ro
  environment:
    - ORO_DB_URL=postgres://oro_db_user:oro_db_pass@pgsql:5432/oro_db
  restart: unless-stopped
  depends_on:
    - pgsql
```

## Testing

### Run All Tests

```bash
CGO_ENABLED=1 go test -v -count=1 ./...
```

### Test Structure

| Test File | Type | Tests | What It Covers |
|---|---|---|---|
| `parser_test.go` | Unit | 7 | Monolog line parsing: standard, empty context, no JSON, critical, invalid, nested JSON, level comparison |
| `entry_test.go` | Unit | 12 | Message key generation, `GroupBatch()` dedup: merge, preserve order, update context, empty, single, large volume (1000 entries) |
| `oroconfig_test.go` | Unit | 8 | `.env-app` parsing, variable substitution, DSN normalization, Oro config discovery (env var, file, local override, missing, explicit path) |
| `integration_test.go` | Integration (SQLite) | 7 | Full DB pipeline: insert batch, upsert merge, accumulate across batches, separate entries, context update, full parse→group→upsert pipeline, file parse + ingest |

**Total: 34 tests** — all using in-memory SQLite, no external dependencies.

### Benchmarks

```bash
CGO_ENABLED=1 go test -bench=. -benchmem ./...
```

| Benchmark | Ops/sec | ns/op | allocs/op |
|---|---|---|---|
| `BenchmarkParseLine` | ~600K | ~1,800 | 6 |
| `BenchmarkGroupBatch` (100 entries) | ~26K | ~53,000 | 805 |

### Build

```bash
# Native
go build -o oro-log-worker .

# Linux binary (cross-compile)
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -ldflags="-s -w" -o oro-log-worker-linux .

# Docker
docker build -t oro-log-worker .
```

## File Structure

```
go-worker/
├── main.go              — entry point, signal handling, goroutine orchestration
├── config.go            — YAML config loading, DSN resolution
├── oroconfig.go         — Oro .env-app discovery + parsing + DSN normalization
├── parser.go            — Monolog line parser (regex, JSON extraction)
├── entry.go             — LogEntry, GroupedEntry, GroupBatch() in-memory dedup
├── tailer.go            — File tailing (nxadm/tail, rotation, poll mode)
├── writer.go            — Batch writer with timer flush, stats, grouping
├── db.go                — DBWriter interface, PgWriter (PostgreSQL), SQL constants
├── db_sqlite.go         — SqliteWriter for integration tests
├── parser_test.go       — Parser unit tests
├── entry_test.go        — Entry + GroupBatch unit tests
├── oroconfig_test.go    — Oro config discovery unit tests
├── integration_test.go  — Full pipeline integration tests (SQLite)
├── config.yaml          — Default config
├── Dockerfile           — Multi-stage build
├── Makefile             — build/run/test/docker targets
├── go.mod / go.sum      — Go module
└── .gitignore           — Ignore binaries
```
