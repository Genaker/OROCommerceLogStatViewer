# GenakerLogViewerBundle

An OroCommerce admin bundle providing five tools for platform operators:

- **Log Viewer** — browse, search, tail, and manage application log files from the admin UI
- **Performance Dashboard** — real-time server metrics (CPU, memory, top processes) and message-queue throughput, aggregated across all PHP-FPM instances
- **SQL Issue Tracker** — detects, persists, and surfaces N+1 queries and slow queries, with AI-powered analysis via configurable LLM providers
- **Browser Console Logger** — inject backend PHP data (API calls, debug info) into the browser developer console with CSP nonce support
- **Database Log Viewer** — write Monolog entries to a database table with a live-tail admin UI, configurable level/channel/write-mode, and automatic cleanup

---

## Why This Exists — OroCloud Developer Visibility Problem

**OroCloud is a black box.** Oro's managed cloud platform deliberately limits direct server access for tenants, which creates a persistent and frustrating gap between what developers need to debug and operate the platform and what they can actually see.

### What OroCloud Does Not Give You

| Problem | Impact |
|---------|--------|
| **No SSH access** | You cannot `tail -f` a log file, run `top`, or inspect a running process. Every server-side debugging action requires an Oro support ticket. |
| **No real-time log access** | Logs are only accessible via the Oro Cloud Console UI, which has a significant delay (minutes, not seconds), no live tail, no grep, and no multi-file comparison. |
| **No server metrics** | There is no built-in UI to see CPU load, memory usage, or which PHP-FPM processes are consuming resources at a given moment. You are flying blind during performance incidents. |
| **No process visibility** | You cannot see which consumers are running, which are stuck, or what their resource consumption looks like across instances. |
| **Multi-instance opacity** | OroCloud runs multiple PHP-FPM instances behind a load balancer. There is no built-in way to see per-instance metrics or compare health across nodes. |
| **Slow support loop** | Diagnosing a production issue requires: open a ticket → wait for Oro support → receive logs hours later → discover the root cause → open another ticket. A full debug cycle can take days. |
| **Log rotation without warning** | Logs are rotated and purged on OroCloud schedules that tenants do not control. By the time you get access, the relevant log window may already be gone. |
| **No MQ consumer throughput** | The message queue consumer dashboard in Oro admin shows job status but not real-time throughput, lag, or per-instance consumer rate. Debugging slow consumers or queue backlogs requires guesswork. |
| **No exception aggregation** | There is no built-in view that groups repeated exceptions, counts their frequency, or highlights error spikes. A single misconfiguration can flood logs with thousands of identical errors that are invisible unless you dig. |

### What This Bundle Fixes

This bundle installs entirely within the OroCommerce admin panel — no SSH, no external tooling, no Oro support ticket required. It gives developers and DevOps the visibility layer that OroCloud withholds:

- **Instant log access** from the browser with live tail, grep, level filter, and exception aggregation
- **Real-time server metrics** (CPU, memory, top processes) pulled from `/proc` on each PHP-FPM instance
- **Multi-instance aggregation** via the shared PSR-6 cache (Redis) — see all nodes in one dashboard
- **MQ consumer throughput** — track message processing rate in real time
- **Error spike detection** — know within seconds when an error flood starts, not minutes later from a support email
- **Zero infrastructure change** — works on OroCloud, on-premise, and in Docker dev environments

> If you have ever waited two hours for an Oro support engineer to share a log file so you could find a one-line exception, this bundle is for you.

---

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Routes](#routes)
6. [ACL Permissions](#acl-permissions)
7. [JavaScript Architecture](#javascript-architecture)
8. [Cache Busting](#cache-busting)
9. [Development Commands](#development-commands)
10. [Testing](#testing)
11. [SQL Issue Tracker Deep Dive](#sql-issue-tracker-deep-dive)

---

## Features

### Log Viewer (`/admin/logs`)

| Feature | Description |
|---------|-------------|
| File list (datagrid) | Lists log files under the configured directory; sortable by name, size, modified time |
| File view | Opens a log file with paginated line display |
| Live tail | Streams new lines in real time via polling |
| Grep / full-text search | Filters displayed lines client-side or via server-side grep |
| Level filter | Toggle display of DEBUG / INFO / WARNING / ERROR / CRITICAL lines |
| Level counts | Per-level line count badge shown in the toolbar |
| Error spike detection | Highlights bursts of error-level lines as a spike indicator |
| Exception aggregator | Groups repeated exception messages and shows occurrence counts |
| Throughput meter | Displays log lines-per-second rate as new lines arrive |
| Multi-file tail | Open two log files side-by-side and tail both simultaneously |
| Split view | Horizontal split panel for comparing sections of a single file |
| Download | Download the raw log file directly from the browser |
| Truncate / delete | Clear or remove log files; requires the `genaker_log_viewer_truncate` ACL |
| Theme toggle | Light/dark mode switch persisted in `localStorage` |
| Window controls | Detach the log viewer into a floating panel |

### Performance Dashboard (`/admin/perf`)

| Feature | Description |
|---------|-------------|
| Instance cards | One card per PHP-FPM instance; auto-discovered via shared cache |
| CPU usage | Real-time CPU utilization percentage per instance |
| Load averages | 1 min / 5 min / 15 min CPU load from `/proc/loadavg` |
| Memory breakdown | Total / used / free / available + usage percentage |
| Disk usage | Root partition usage with total/used/free and percentage |
| Uptime | System uptime displayed in card header (e.g., "3d 14h") |
| CPU cores | Core count from `/proc/cpuinfo` |
| Top processes | Top-10 processes by CPU/memory (readable accordion, collapsed by default) |
| MQ report | Message-queue consumer throughput from `PerfMqReportExtension` listener |
| Auto-refresh | Configurable polling interval; play/pause control |
| Multi-instance aggregation | Metrics stored in the PSR-6 cache (Redis in production); all instances share a common registry key |
| Configurable reporting | Choose which listeners trigger metric pushes (HTTP, MQ before/after, both) |
| Adjustable interval | Configure report interval (5–300 seconds) to balance update frequency vs Redis load |

### SQL Issue Tracker (`/admin/sql-issues`)

| Feature | Description |
|---------|-------------|
| N+1 detection | Flags queries whose identical SQL template fires more than once per request with the same parameters |
| Slow query detection | Flags queries exceeding a configurable threshold (default: 200 ms) |
| Persistent storage | Detected issues are stored in the `genaker_sql_issue` PostgreSQL table with upsert logic — duplicates are merged, not duplicated |
| Datagrid | Admin grid at `/admin/sql-issues` with sortable/filterable columns: N+1, Slow, Worst N+1 count, Worst slow ms, Occurrences, Last Seen, Last URL, Caller, SQL Template, Params, Suggestion, Analysis, AI Prompt |
| Expandable columns | SQL Template, Params, Caller, Suggestion, Analysis, and AI Prompt cells use `<details>` expand/collapse for compact display |
| Analysis column | Per-issue stats: execution count, unique param sets, avg/min/max ms, total ms, EXPLAIN plan summary (node type, planner cost, indexes used, filter conditions) |
| AI Prompt column | Auto-generated LLM prompt containing the SQL template, execution stats, and EXPLAIN plan — ready to copy or send directly to the configured AI provider |
| Copy button | One-click clipboard copy of the AI prompt using `navigator.clipboard` with `execCommand` fallback |
| Ask AI button | Sends the stored prompt to the configured AI provider (OpenAI, Anthropic, or generic OpenAI-compatible endpoint) and persists the response |
| Re-ask AI | Button label switches to "Re-ask AI" after first response; can be invoked again to refresh analysis |
| AI provider config | Provider (openai/anthropic/generic), API key, model, and API URL are all configurable in System Configuration |
| Clear all | One-click button to truncate all tracked issues and start fresh |
| Auto-detection | Issues are captured via `SqlIssueTrackerListener` on `kernel.terminate` — zero overhead on the critical path |

### Browser Console Logger

A standalone PHP service that passes backend data to the browser developer console — no JS files, no twig changes needed. Works on both storefront and admin pages.

| Feature | Description |
|---------|-------------|
| Multiple log methods | `log()`, `info()`, `warn()`, `error()`, `debug()`, `table()`, `group()` / `groupEnd()` |
| Auto-injection | A `kernel.response` listener injects a `<script>` block before `</body>` in HTML responses |
| CSP nonce | Every injected script tag gets a unique `nonce` attribute; appended to `Content-Security-Policy` header if present |
| Memory limits | Configurable max entries (default: 200) and max payload size (default: 1 MB) with truncation warning |
| Object normalization | Automatically serializes Throwables, JsonSerializable objects, and stringable objects |
| `[PHP]` prefix | All console entries are prefixed with `[PHP]` for easy filtering in browser DevTools |

**Usage from any PHP service:**

```php
public function __construct(
    private readonly BrowserConsoleLogger $consoleLogger,
) {}

public function someMethod(): void
{
    $this->consoleLogger->group('API Call', collapsed: true);
    $this->consoleLogger->log('Request payload', $requestData);
    $this->consoleLogger->warn('Slow response', ['ms' => $elapsed]);
    $this->consoleLogger->table('Line items', $rows);
    $this->consoleLogger->groupEnd();
}
```

### Database Log Viewer (`/admin/log-entries`)

Writes Monolog entries to a PostgreSQL table and provides a terminal-style live-tail UI in the admin panel. File logging always remains active — this feature adds a parallel DB copy for searchable, structured log access.

![Database Log Viewer — Light Theme](Resources/doc/images/db-log-viewer-light.png)

*Database Log Viewer showing grouped log entries with occurrence counts, level badges, expandable context/message/URL columns, column sorting, resizable headers, and real-time stats bar.*

#### Monolog Handler — How It Works

The `DatabaseLogHandler` is registered into Monolog's handler chain via a compiler pass at container build time. It starts **disabled** and is toggled on by the `DatabaseLogConfigListener` on the first `kernel.request` (HTTP) or `console.command` (CLI) event, based on admin config or the `GENAKER_DB_LOG_ENABLED` env var.

| Feature | Description |
|---------|-------------|
| File + DB logging | Additional Monolog handler with `bubble: true` — records always pass through to the default file handler first, then optionally to DB |
| Deferred writes (default) | Buffers entries in memory during the request. Flushed to DB **after** the response is sent via three hooks: `kernel.terminate` (HTTP), `console.terminate` (CLI), `onPostReceived` (MQ consumers) — zero impact on response time |
| Immediate mode | Writes each entry to DB inline as it happens — useful for debugging crashes or segfaults where the process may die before the deferred flush fires |
| Level filtering | Configurable minimum log level for DB writes (default: WARNING). Set to DEBUG to capture everything. |
| Channel filtering | Comma-separated list of Monolog channels to capture (e.g. `app,security,doctrine`). Leave empty to log all channels. |
| Env var override | `GENAKER_DB_LOG_ENABLED=1` overrides the admin config toggle — works in `.env`, `docker-compose.yml`, or PHP-FPM pool config. Set to `0` to force-disable. When the env var is not set, the admin config value is used. |
| Re-entrancy guard | Static `$flushing` flag prevents infinite loops if a DB write itself triggers a log entry |
| Silent failure | DB insert errors are silently dropped — a failed log write never crashes the application |
| Auto-truncation | Built-in table size monitoring — when the table exceeds the configured max (default: 500 MB), oldest rows are automatically deleted. Checked every N minutes (default: 15), not on every write. No cron required. |
| Message truncation | Messages longer than 65,535 characters are truncated before insert |
| JSON context/extra | Context and extra arrays are stored as JSONB columns, fully searchable and expandable in the UI |

#### Flush Events — All Three Runtimes Covered

| Runtime | Flush trigger | Class |
|---------|---------------|-------|
| HTTP requests | `kernel.terminate` (after response sent to client) | `DatabaseLogFlushListener` |
| CLI commands | `console.terminate` (after command finishes) | `DatabaseLogFlushListener` |
| MQ consumers | `onPostReceived` (after each message processed) | `DatabaseLogMqFlushExtension` |

The config listener also fires on `console.command` (priority 4096) so CLI commands get the correct settings before they start logging.

#### Live Tail UI Features

| Feature | Description |
|---------|-------------|
| Terminal-style interface | Dark Catppuccin Mocha theme with macOS-style title bar (matching the file log viewer aesthetic) |
| Light/dark theme toggle | Click the theme button to switch between dark and light themes; preference saved in `localStorage` |
| Level filter | Dropdown to show only DEBUG, INFO, WARNING, ERROR, CRITICAL, etc. |
| Channel filter | Dropdown auto-populated from distinct channels in the DB |
| Message search | Free-text search on the message column (SQL `LIKE`) |
| Configurable row count | Load 10–500 rows per fetch (default: 100) |
| Live tail polling | 3-second auto-refresh that fetches only new rows (by `after_id`) — no duplicate data transfer. Auto-sorts by time (newest first) when live. |
| Pause/resume | Stop live polling without leaving the page |
| Clear view | Clear the displayed rows without deleting data from DB |
| Column sorting | Click any column header to sort (ascending/descending toggle with arrow indicator). Context column excluded. |
| Resizable columns | Drag column header border to resize; visible border separators between all columns. Message column defaults to 400px (widest). |
| Expandable cells | Click Message, Context, or URL cells to expand/collapse full content. Context is truncated to configurable length (default: 100 chars). |
| Context trim | Context JSON truncated to N characters in collapsed view (configurable, default 100). Set to 0 for full display. |
| Occurrence count column | Color-coded badge: green (<10), yellow (<100), red (100+). Shows how many times a grouped log entry was seen. |
| First/last seen timestamps | For grouped entries (count > 1), shows both last seen (top) and first seen (bottom, smaller gray text) |
| URL and IP tracking | Captures `REQUEST_URI` and `REMOTE_ADDR` for each log entry |
| Stats bar | Always-visible footer showing: Shown rows, DB Rows total, Total Events (sum of all occurrence counts), Table Size (MB/GB), Grouped count, Last refresh, Max ID |
| Clear All button | Delete all log entries from the database (with confirmation dialog) |

#### Log Grouping / Deduplication

Instead of storing every duplicate log entry, identical messages are merged into one row with an incrementing `occurrence_count`. This dramatically reduces table size for repetitive logs.

| Feature | Description |
|---------|-------------|
| Grouping key | MD5 hash of `channel\|level\|first N characters of message` (default N=30, configurable) |
| PostgreSQL upsert | `INSERT ... ON CONFLICT (message_key) DO UPDATE` — single atomic SQL, no race conditions |
| Count tracking | `occurrence_count` increments on each duplicate |
| Timestamp tracking | `first_seen_at` preserves original time, `created_at` updates to latest occurrence |
| Latest context kept | On duplicate, the latest `context`, `url`, and `ip` overwrite the previous values |
| Enabled by default | Configurable via admin config or can be disabled for full verbosity |
| Configurable key length | Shorter key = more aggressive grouping (default 30). Lower to ~20 for Symfony "No routes found" messages. |

#### Database Schema

```sql
CREATE TABLE genaker_log_entry (
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
    message_key      VARCHAR(64)  UNIQUE,  -- MD5 grouping key (NULL = ungrouped)
    occurrence_count INTEGER      NOT NULL DEFAULT 1,
    first_seen_at    TIMESTAMP
);
-- Indexes: channel, level, created_at, message_key (unique)
```

#### Enabling the Feature

**Option 1 — Admin UI** (recommended for staging/dev):

Go to **System > Configuration > Platform > General Setup > Log Viewer & Monitoring > Database Log Handler** and check "Enable Database Log Handler". Set the desired level, write mode, and channels, then save.

**Option 2 — Environment variable** (recommended for Docker/CI):

```bash
# In .env, docker-compose.yml, or PHP-FPM pool config
GENAKER_DB_LOG_ENABLED=1
```

The env var takes precedence over the admin config. The handler reads the remaining settings (level, write mode, channels) from admin config even when the env var is used.

**Option 3 — Direct database insert** (when admin UI config save isn't working):

```sql
INSERT INTO oro_config_value (config_id, name, section, text_value, type, created_at, updated_at)
VALUES (2, 'db_log_enabled', 'genaker_log_viewer', '1', 'scalar', NOW(), NOW());
INSERT INTO oro_config_value (config_id, name, section, text_value, type, created_at, updated_at)
VALUES (2, 'db_log_level', 'genaker_log_viewer', 'DEBUG', 'scalar', NOW(), NOW());
```

Then clear cache: `php bin/console cache:clear --env=dev`

#### Auto-Truncation (built-in, no cron needed)

The handler includes built-in table size monitoring. After each flush (deferred mode) or periodically during immediate writes, it checks if the `genaker_log_entry` table exceeds the configured max size and automatically deletes the oldest rows to stay under the limit.

| Setting | Default | Description |
|---------|---------|-------------|
| `db_log_max_size_mb` | 500 | Maximum table size in MB. When exceeded, oldest rows are deleted. Set to 0 to disable. |
| `db_log_truncate_interval_min` | 15 | How often to check the table size (in minutes). Uses `pg_total_relation_size()` which is very fast — no sequential scan. |

**How it works:**

1. After `flush()` or after immediate-mode writes, the handler checks if enough time has passed since the last check (default: 15 minutes)
2. If due, it calls `pg_total_relation_size('genaker_log_entry')` to get the actual table size including indexes and TOAST data
3. If the table is over the limit, it calculates average row size from `total_size / row_count` (using `pg_class.reltuples` for fast row count estimate)
4. It deletes enough oldest rows to bring the table back under the limit, plus 20% headroom to avoid re-triggering on the next check
5. A safety cap prevents deleting more than 90% of the table in one pass

**Example:** If the table is 600 MB with 2M rows (300 bytes/row avg) and the limit is 500 MB, it needs to free 100 MB. With 20% headroom: 120 MB / 300 bytes = ~400K oldest rows deleted.

All errors are silently caught — a failed size check or delete never breaks logging.

#### Auto-Cleanup (Cron — optional, complementary)

The cron command provides time-based cleanup as a complement to the size-based auto-truncation:

```bash
# Delete entries older than 24 hours, every hour
0 * * * * php bin/console genaker:log-entry:cleanup --hours=24

# Or match the configured retention period
0 * * * * php bin/console genaker:log-entry:cleanup --hours=48
```

The `--hours` flag defaults to 24. The retention period is also configurable in admin config (`db_log_retention_hours`). The cron is optional if auto-truncation by size is sufficient for your use case.

#### Architecture — Key PHP Classes

| Class | Purpose |
|-------|---------|
| `Handler/DatabaseLogHandler` | Monolog handler — buffers or writes directly, with channel/level filtering and re-entrancy guard |
| `DependencyInjection/Compiler/RegisterDatabaseLogHandlerPass` | Compiler pass — pushes handler onto Monolog's logger at container build time |
| `EventListener/DatabaseLogConfigListener` | `kernel.request` / `console.command` listener — reads config/env var and enables/configures the handler |
| `EventListener/DatabaseLogFlushListener` | `kernel.terminate` / `console.terminate` listener — flushes deferred buffer to DB |
| `Consumption/DatabaseLogMqFlushExtension` | MQ extension — flushes deferred buffer after each message |
| `Controller/LogEntryController` | Admin controller — index page, AJAX tail/channels endpoints, clear all |
| `Command/CleanupLogEntriesCommand` | `genaker:log-entry:cleanup` — deletes old entries |
| `Entity/LogEntry` | Doctrine entity mapping for `genaker_log_entry` table |

---

## Requirements

- OroCommerce EE 6.1+
- PHP 8.4+
- Symfony 6.4+
- Redis (or any PSR-6 `cache.app` pool) for multi-instance metric sharing
- `/proc` filesystem available on the host (standard on Linux)

---

## Installation

The bundle is auto-registered via `Resources/config/oro/bundles.yml`.

Add the autoload entry in `composer.json` if it is not already present:

```json
"autoload": {
    "psr-4": {
        "Genaker\\": "src/Genaker/"
    }
}
```

Then run:

```bash
composer dump-autoload
php bin/console cache:clear --env=dev
php bin/console assets:install --symlink
```

---

## Configuration

Settings are available under **System → Configuration → Platform → Log Viewer & Monitoring**.

### Log Viewer Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.enabled` | boolean | `true` | Disable on production to prevent log file exposure |
| `genaker_log_viewer.lines_per_page` | integer | — | Default number of lines shown per page (10–1000) |

### Server Monitoring Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.perf_dashboard_enabled` | boolean | `true` | Enable real-time server metrics collection. Disable to reduce Redis load. |
| `genaker_log_viewer.perf_report_interval` | integer | `60` | Metrics report interval in seconds (5–300). Lower = more frequent updates, higher Redis load. |
| `genaker_log_viewer.perf_http_reporting` | boolean | `true` | Report metrics on `kernel.terminate` (after HTTP response). Recommended for web servers. |
| `genaker_log_viewer.perf_mq_reporting` | boolean | `true` | Report metrics from RabbitMQ consumers. Recommended for background workers. |
| `genaker_log_viewer.perf_mq_trigger` | choice | `after` | **When to report from MQ consumers:**<br>• `before` — Before processing message (less frequent)<br>• `after` — After processing message (more frequent, recommended for RabbitMQ)<br>• `both` — Both hooks (highest update frequency) |

**RabbitMQ Optimization:**

The `perf_mq_trigger` setting controls when RabbitMQ consumers report metrics:

- **`after` (recommended)**: Reports *after* each message is processed → metrics update every time a job completes (much more frequent than the 60s interval)
- **`before`**: Reports *before* processing → less frequent updates, only when the consumer wakes up
- **`both`**: Reports on both hooks → maximum update frequency but also maximum Redis load

**Why `after` is better for RabbitMQ:**

On a busy queue processing 10 messages per minute, `after` mode gives you 10 metric updates per minute instead of just 1 (the rate-limit interval). This is critical for monitoring background workers where you need to see resource consumption *during* job processing, not just when the consumer is idle.

**Disabling monitoring:**

Set `perf_dashboard_enabled` to `false` to completely disable metrics collection. This skips all instrumentation, saves CPU cycles, and eliminates Redis writes. The dashboard will show no instances. Re-enable to resume monitoring.

The log directory and other low-level parameters are set in `Resources/config/services.yml` via constructor injection into `LogFileReader` and `LogFileValidator`.

### SQL Issue Tracker Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.sql_tracking_enabled` | boolean | `true` | Enable/disable SQL issue detection entirely |
| `genaker_log_viewer.sql_n1_threshold` | integer | `2` | Minimum repeat count to flag a query as N+1 |
| `genaker_log_viewer.sql_slow_threshold_ms` | integer | `200` | Query duration threshold in milliseconds to flag as slow |

### Browser Console Logger Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.browser_console_enabled` | boolean | `true` | Inject backend log data into the browser developer console. Disable on production. |
| `genaker_log_viewer.browser_console_max_entries` | integer | `200` | Maximum log entries per request (1–10,000) |
| `genaker_log_viewer.browser_console_max_size_kb` | integer | `1024` | Maximum total payload injected into the page in KB (1–10,240) |

### Database Log Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.db_log_enabled` | boolean | `false` | Write Monolog entries to the `genaker_log_entry` table. Can be overridden by `GENAKER_DB_LOG_ENABLED` env var. |
| `genaker_log_viewer.db_log_level` | choice | `WARNING` | Minimum log level written to DB (DEBUG through EMERGENCY) |
| `genaker_log_viewer.db_log_write_mode` | choice | `deferred` | `deferred` — flush to DB after response/command/MQ message (recommended). `immediate` — write inline during request (for crash debugging). |
| `genaker_log_viewer.db_log_channels` | string | _(empty = all)_ | Comma-separated Monolog channels to capture. Example: `app,security,doctrine` |
| `genaker_log_viewer.db_log_retention_hours` | integer | `24` | Entries older than this are deleted by `genaker:log-entry:cleanup` |
| `genaker_log_viewer.db_log_max_size_mb` | integer | `500` | Max table size in MB. When exceeded, oldest rows auto-deleted on next write cycle. 0 = disabled. |
| `genaker_log_viewer.db_log_truncate_interval_min` | integer | `15` | How often to check table size for auto-truncation (minutes) |
| `genaker_log_viewer.db_log_grouping_enabled` | boolean | `true` | Merge duplicate entries by channel+level+message prefix (upsert with occurrence count) |
| `genaker_log_viewer.db_log_grouping_key_length` | integer | `30` | Characters of message prefix used for grouping key (10–255). Shorter = more aggressive dedup. |
| `genaker_log_viewer.db_log_context_trim_length` | integer | `100` | Truncate context JSON display to N chars in the UI. 0 = show full context. |

### AI Analysis Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.ai_provider` | choice | `openai` | LLM provider: `openai`, `anthropic`, or `generic` (any OpenAI-compatible endpoint) |
| `genaker_log_viewer.ai_api_key` | string | — | API key for the configured provider |
| `genaker_log_viewer.ai_model` | string | `gpt-4o` | Model identifier (e.g. `gpt-4o`, `claude-3-5-sonnet-20241022`) |
| `genaker_log_viewer.ai_api_url` | string | — | Custom API endpoint URL (required for `generic` provider; overrides default for others) |

**Provider endpoint defaults:**

| Provider | Default URL |
|----------|-------------|
| `openai` | `https://api.openai.com/v1/chat/completions` |
| `anthropic` | `https://api.anthropic.com/v1/messages` |
| `generic` | Must be set explicitly in `ai_api_url` |

---

## Routes

| Name | Method | Path | Description |
|------|--------|------|-------------|
| `genaker_log_viewer_index` | GET | `/admin/logs` | Log file list |
| `genaker_log_viewer_view` | GET | `/admin/logs/view/{fileName}` | View a single log file |
| `genaker_log_viewer_tail` | GET | `/admin/logs/tail/{fileName}` | Live-tail endpoint (JSON stream) |
| `genaker_log_viewer_reload` | GET | `/admin/logs/reload/{fileName}` | Reload file content |
| `genaker_log_viewer_grep` | GET | `/admin/logs/grep/{fileName}` | Server-side grep endpoint |
| `genaker_log_viewer_multi_tail` | GET | `/admin/logs/multi-tail` | Multi-file tail endpoint |
| `genaker_log_viewer_download` | GET | `/admin/logs/download/{fileName}` | Download log file |
| `genaker_log_viewer_delete` | POST | `/admin/logs/delete/{fileName}` | Delete log file |
| `genaker_perf_dashboard_index` | GET | `/admin/perf` | Performance dashboard |
| `genaker_perf_dashboard_metrics` | GET/POST | `/admin/perf/metrics` | Metrics push/pull API |
| `genaker_sql_issue_index` | GET | `/admin/sql-issues` | SQL Issue Tracker grid |
| `genaker_sql_issue_clear` | POST | `/admin/sql-issues/clear` | Clear all tracked issues |
| `genaker_sql_issue_ask_ai` | POST | `/admin/sql-issues/{id}/ask-ai` | Trigger AI analysis for one issue; returns `{analysis: string}` or `{error: string}` |
| `genaker_log_entry_index` | GET | `/admin/log-entries` | Database log viewer (live tail UI) |
| `genaker_log_entry_tail` | GET | `/admin/log-entries/tail` | AJAX tail endpoint with level/channel/search filters |
| `genaker_log_entry_channels` | GET | `/admin/log-entries/channels` | List distinct channels in log table |
| `genaker_log_entry_clear_all` | POST | `/admin/log-entries/clear-all` | Delete all log entries |
| `genaker_log_entry_stats` | GET | `/admin/log-entries/stats` | Table stats (row count, size, grouping info) |

---

## ACL Permissions

Defined in `Resources/config/oro/acls.yml`:

| ACL resource | Label | Grants access to |
|---|---|---|
| `genaker_log_viewer_index` | View Log Files | Log file list and all read-only log actions |
| `genaker_log_viewer_truncate` | Truncate Log Files | Delete and truncate log file actions |
| `genaker_perf_dashboard_index` | View Performance Dashboard | Performance dashboard and metrics API |
| `genaker_sql_issue_index` | View SQL Issue Tracker | SQL issue grid and clear action |
| `genaker_log_entry_index` | View Database Logs | Database log viewer, tail endpoint, channels, and clear action |

Assign these ACL resources to roles under **System → User Management → Roles**.

---

## JavaScript Architecture

Frontend code uses the **OroCommerce AMD mixin pattern**. Each concern is isolated in its own mixin file under `Resources/public/js/app/components/`:

```
log-viewer-component.js             — main view component (entry point)
log-viewer-index-component.js       — file list page component
log-viewer-live-tail-mixin.js       — live-tail polling
log-viewer-grep-mixin.js            — grep / text search
log-viewer-level-filter-mixin.js    — show/hide by log level
log-viewer-level-counts-mixin.js    — per-level count badges
log-viewer-error-spike-mixin.js     — burst / spike detection
log-viewer-exception-aggregator-mixin.js — exception grouping
log-viewer-throughput-mixin.js      — lines-per-second meter
log-viewer-multi-mixin.js           — multi-file side-by-side tail
log-viewer-split-mixin.js           — split-panel view
log-viewer-download-btn-mixin.js    — download button
log-viewer-theme-wrap-mixin.js      — light/dark theme toggle
log-viewer-window-controls-mixin.js — floating/detach panel
perf-dashboard-component.js         — performance dashboard
```

Each mixin exports a plain object with methods. The main component assembles them at runtime:

```javascript
// Conceptual pattern (simplified)
BaseComponent.extend(
    Object.assign({}, liveTailMixin, grepMixin, levelFilterMixin, ...)
);
```

Mixin methods reference `this` (the component instance). AMD module globals use `globalThis` (e.g. `globalThis.location.href`) per OroCommerce coding standards.

---

## Cache Busting

### CSS

The `FileVersionExtension` Twig extension provides a `file_version()` function that returns the `filemtime()` of any file under `public/`. This is appended as a query string to every CSS `<link>` tag:

```twig
<link rel="stylesheet"
      href="/bundles/genakerlogviewer/css/perf-dashboard.css?v={{ file_version('bundles/genakerlogviewer/css/perf-dashboard.css') }}">
```

The version changes automatically whenever the file is saved — no build step required.

### JS Chunks

`webpack.config.js` includes a `BumpVersionPlugin` that writes a new md5 hash to `public/build/build_version.txt` after every webpack compilation. OroCommerce's asset pipeline reads this file to version all chunk URLs.

---

## Development Commands

```bash
# Rebuild webpack chunk (JS change)
# IMPORTANT: use oro:assets:build, NOT direct webpack — it compiles jsmodules.yml first
php bin/console oro:assets:build admin.oro --env=dev

# CSS-only change (no webpack needed — filemtime auto-busts)
# Just save the .scss/.css file; no extra command required.

# Force version bump + cache clear (CSS)
npm run bump-css

# Full rebuild (CSS change + webpack + cache clear)
npm run rebuild-css

# Clear Symfony cache
php bin/console cache:clear --env=dev

# Reinstall bundle assets
php bin/console assets:install --symlink

# Dump autoloader (after adding/moving PHP classes)
composer dump-autoload
```

---

## Testing

### Unit Tests

```bash
# Run all unit tests for this bundle
cd /oro-ee && ./bin/phpunit -c phpunit-dev.xml \
    src/Genaker/Bundle/LogViewerBundle/Tests/Unit/ --no-coverage

# Run integration tests (requires running app + database)
cd /oro-ee && ORO_ENV=dev ./bin/phpunit -c phpunit-dev.xml \
    --testsuite=shipping-cart --no-coverage

# Run a single test class
cd /oro-ee && ./bin/phpunit -c phpunit-dev.xml \
    --filter LogFileReaderTest --no-coverage

# Run database log integration tests (requires local PostgreSQL)
INTEGRATION_TESTS_ENABLED=1 php bin/phpunit -c phpunit-dev.xml \
    --filter DatabaseLogIntegrationTest --no-coverage
```

Current test status: **343 unit tests + 21 integration tests — all passing**.

### E2E Tests (Playwright + Python)

End-to-end tests use Playwright to validate UI behavior in a real browser.

**Prerequisites:**

```bash
# Python venv already set up at /oro-ee/var/tmp/venv with:
# - pytest
# - pytest-playwright
# - playwright (Chromium browser installed)
```

**Run all E2E tests:**

```bash
cd /oro-ee && /oro-ee/var/tmp/venv/bin/pytest tests/e2e/ -v
```

**Run only LogViewer E2E tests:**

```bash
cd /oro-ee && /oro-ee/var/tmp/venv/bin/pytest tests/e2e/test_admin_log_viewer.py -v
```

**Run only Performance Dashboard E2E tests:**

```bash
cd /oro-ee && /oro-ee/var/tmp/venv/bin/pytest tests/e2e/test_admin_perf_dashboard.py -v
```

**Run only SQL Issue Tracker E2E tests:**

```bash
cd /oro-ee && /oro-ee/var/tmp/venv/bin/pytest \
    src/Genaker/Bundle/LogViewerBundle/Tests/E2E/test_sql_issues.py -v
```

**Run with browser visible (debug mode):**

```bash
cd /oro-ee && PLAYWRIGHT_HEADLESS=0 /oro-ee/var/tmp/venv/bin/pytest tests/e2e/test_admin_log_viewer.py -v -s
cd /oro-ee && PLAYWRIGHT_HEADLESS=0 /oro-ee/var/tmp/venv/bin/pytest tests/e2e/test_admin_perf_dashboard.py -v -s
```

**Run specific test:**

```bash
cd /oro-ee && /oro-ee/var/tmp/venv/bin/pytest \
    tests/e2e/test_admin_log_viewer.py::TestAdminLogViewerAccess::test_authenticated_admin_loads_page -v
cd /oro-ee && /oro-ee/var/tmp/venv/bin/pytest \
    tests/e2e/test_admin_perf_dashboard.py::TestAdminPerfDashboardCards::test_load_averages_present -v
```

**E2E test coverage for LogViewerBundle:**

| Test file | Coverage |
|-----------|----------|
| `test_admin_log_viewer.py` | Log viewer page access, grid rendering, column headers, log file visibility |
| `test_admin_perf_dashboard.py` | Performance dashboard access, instance cards, CPU/memory/disk metrics, load averages (1m/5m/15m), controls |
| `test_log_viewer_link.py` | Log viewer link in admin navigation |
| `test_sql_issues.py` | SQL Issue Tracker — access, grid rendering, column presence, data recording, system config fields, AI feature (endpoint, button, prompt textarea) |

**Expected E2E test results:**

**Log Viewer (`test_admin_log_viewer.py`):**
- `TestAdminLogViewerAccess` — 2 tests (auth required, admin access)
- `TestAdminLogViewerPage` — 4 tests (no 500 errors, no exceptions, grid name, column headers)
- `TestAdminLogViewerGridData` — 5 tests (grid rows, log files, dev.log visible, file sizes, row count)

**Performance Dashboard (`test_admin_perf_dashboard.py`):**
- `TestAdminPerfDashboardAccess` — 2 tests (auth required, admin access)
- `TestAdminPerfDashboardPage` — 4 tests (no 500 errors, no exceptions, shell renders, page title)
- `TestAdminPerfDashboardControls` — 3 tests (refresh button, auto-refresh toggle, theme toggle)
- `TestAdminPerfDashboardStats` — 3 tests (header stats pills, hosts stat, peak load stat)
- `TestAdminPerfDashboardCards` — 8 tests (instance cards, CPU/memory/disk metrics, load averages 1m/5m/15m, uptime, hostname, cores)
- `TestAdminPerfDashboardInteraction` — 2 tests (theme toggle works, refresh button triggers update)

**Note on test failures:** If E2E tests fail after updating the LogViewer bundle:

**Log Viewer troubleshooting:**
1. Check if the grid name changed (expected: `egerdau_log_files_grid`)
2. Verify column headers match: `['File Name', 'Size (bytes)', 'Last Modified']`
3. Confirm the route `/admin/logs` is accessible
4. Ensure log files exist in `var/logs/` (e.g., `dev.log`, `prod.log`)
5. Check if ACL permissions are configured correctly (`genaker_log_viewer_index`)

**Performance Dashboard troubleshooting:**
1. Verify the route `/admin/perf` is accessible
2. Check if `perf_dashboard_enabled` is `true` in system configuration
3. Ensure Redis is running (metrics are stored in Redis)
4. Verify RabbitMQ consumers are reporting metrics (check `perf_mq_reporting` config)
5. Wait 60 seconds after enabling monitoring for first metrics to appear
6. Check browser console for JavaScript errors in `perf-dashboard-component.js`

**Admin authentication issues:**
- If ALL authenticated tests fail with "Admin login failed — still on: http://localhost:8000/admin/user/login":
  - This is an infrastructure issue with OroCommerce admin authentication
  - Check if admin user is LOCKED in database: `php bin/console oro:user:list --env=dev`
  - Update test credentials in `.env-app.local`: `ORO_TEST_ADMIN_USERNAME` and `ORO_TEST_ADMIN_PASSWORD`
  - Ensure test admin user is Active (not Locked)
  - See `E2E_FIX_SUMMARY.md` for detailed diagnosis

If E2E tests were passing before your changes and fail after, the changes likely broke something. If they were already failing, the issue is pre-existing and unrelated to your work.

### Static Analysis

```bash
# PHP CodeSniffer (PSR-12)
php bin/phpcs --standard=phpcs.xml --extensions=php \
    src/Genaker/Bundle/LogViewerBundle \
    --ignore=*/Tests/*,*/Migrations/*,*/DataFixtures/*

# PHP Mess Detector
php -d auto_prepend_file=phpmd-rules/autoload.php bin/phpmd \
    src/Genaker/Bundle/LogViewerBundle text phpmd.xml

# ESLint (JS)
npx eslint src/Genaker/Bundle/LogViewerBundle/Resources/public/js/
```

---

## Namespace Reference

| Item | Value |
|------|-------|
| PHP namespace | `Genaker\Bundle\LogViewerBundle` |
| Bundle class | `Genaker\Bundle\LogViewerBundle\GenakerLogViewerBundle` |
| DI extension alias | `genaker_log_viewer` |
| Public assets path | `public/bundles/genakerlogviewer/` |
| Translation domain | `messages` (keys prefixed `genaker_log_viewer.*`) |
| Log datagrid name | `genaker-log-files-grid` |
| SQL issues datagrid | `genaker_sql_issue_grid` |
| SQL issue DB table | `genaker_sql_issue` |
| Log entry DB table | `genaker_log_entry` |
| Browser console logger | `Genaker\Bundle\LogViewerBundle\Service\BrowserConsoleLogger` |
| Database log handler | `Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler` |
| DB log env var override | `GENAKER_DB_LOG_ENABLED=1\|0` |
| JS module prefix | `genakerlogviewer/js/app/components/` |

---

## SQL Issue Tracker Deep Dive

### How Detection Works

`SqlIssueTrackerListener` subscribes to `kernel.terminate`. After the response is sent, it reads all Doctrine DBAL queries from `DebugStack` and analyzes them:

1. **N+1 detection** — groups queries by normalized SQL template (parameter values stripped). If the same template fires ≥ `sql_n1_threshold` times with identical parameter sets, it is flagged as N+1.
2. **Slow query detection** — any query whose execution time exceeds `sql_slow_threshold_ms` is flagged as slow.
3. **EXPLAIN** — for flagged queries, an `EXPLAIN (FORMAT JSON)` is run to capture planner metadata (node type, cost, indexes, filter conditions).
4. **Upsert** — results are merged into `genaker_sql_issue` via PostgreSQL `INSERT ... ON CONFLICT (sql_hash) DO UPDATE`. The same logical query accumulates stats across requests rather than creating duplicate rows.
5. **AI Prompt generation** — `SqlIssueAnalyzer` constructs a structured prompt from the SQL template, execution stats, EXPLAIN output, and suggestion. The prompt is stored in `analysis_data->aiPrompt` (JSONB).

### Database Schema

```sql
CREATE TABLE genaker_sql_issue (
    id           SERIAL PRIMARY KEY,
    sql_hash     VARCHAR(64) NOT NULL UNIQUE,  -- SHA-256 of normalized SQL
    sql_template TEXT        NOT NULL,          -- parameterized SQL
    is_n1        BOOLEAN     NOT NULL DEFAULT FALSE,
    is_slow      BOOLEAN     NOT NULL DEFAULT FALSE,
    worst_n1_count   INT     NOT NULL DEFAULT 0,
    worst_slow_ms    NUMERIC NOT NULL DEFAULT 0,
    occurrence_count INT     NOT NULL DEFAULT 0,
    last_seen_at TIMESTAMP   NOT NULL,
    last_url     TEXT,
    last_caller  TEXT,
    last_params  JSONB,
    suggestion   TEXT,
    analysis_data JSONB       -- stats, EXPLAIN plan, aiPrompt, aiAnalysis
);
```

### AI Analysis Flow

```
User clicks "Ask AI" in the grid
    ↓
POST /admin/sql-issues/{id}/ask-ai
    ↓
SqlIssueController::askAiAction()
    ↓
SqlAiAnalyzer::analyseFromPrompt($issue)
    ├── Reads ai_provider, ai_api_key, ai_model, ai_api_url from PerfDashboardConfig
    ├── Calls provider API with stored aiPrompt
    └── Saves analysis text to analysis_data->aiAnalysis via EntityManager
    ↓
JSON response: {"analysis": "..."} or {"error": "..."}
    ↓
Browser updates ai-result-{id} div and switches button to "Re-ask AI"
```

### Key PHP Classes

| Class | Purpose |
|-------|---------|
| `Entity/SqlIssue` | Doctrine entity mapping the `genaker_sql_issue` table |
| `EventListener/SqlIssueTrackerListener` | `kernel.terminate` subscriber — detects and persists issues |
| `Service/SqlIssueAnalyzer` | Builds the AI prompt from execution stats + EXPLAIN plan |
| `Service/SqlAiAnalyzer` | Calls the configured LLM API with the stored prompt |
| `Controller/SqlIssueController` | Routes: index, clear, ask-ai |
| `Repository/SqlIssueRepository` | Doctrine repository with upsert and clear methods |
| `DependencyInjection/Configuration` | System config schema for SQL tracking + AI settings |

### Datagrid Column Reference

| Column key | Data source | Template |
|------------|-------------|----------|
| `isN1` | `s.isN1` | `is_n1.html.twig` — red badge |
| `isSlow` | `s.isSlow` | `is_slow.html.twig` — orange badge |
| `worstN1Count` | `s.worstN1Count` | integer |
| `worstSlowMs` | `s.worstSlowMs` | decimal |
| `occurrenceCount` | `s.occurrenceCount` | integer |
| `lastSeenAt` | `s.lastSeenAt` | datetime |
| `lastUrl` | `s.lastUrl` | plain text |
| `lastCaller` | `s.lastCaller` | `last_caller.html.twig` — multiline wrap |
| `sqlTemplate` | `s.sqlTemplate` | `sql_template.html.twig` — truncated `<details>` |
| `lastParams` | `s.lastParams` | `last_params.html.twig` — JSON pretty-print `<details>` |
| `suggestion` | `s.suggestion` | `suggestion.html.twig` |
| `aiPrompt` | `s.analysisData` | `ai_prompt.html.twig` — Copy + Ask AI buttons |
| `analysisData` | `s.analysisData` | `analysis_data.html.twig` — stats + EXPLAIN table |
