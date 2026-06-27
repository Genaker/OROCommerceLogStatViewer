# OroCommerce Logging Is Broken — Here's How We Fixed It with a Go Worker and Database Log Viewer

## The Problem: You Can't See Your Own Logs

If you've worked with OroCommerce on OroCloud, you know the pain. You deploy code, something breaks, and you need to check the logs. Simple, right?

Not on OroCloud.

OroCloud is a managed hosting platform that deliberately restricts developer access to the server. There's no SSH. No `tail -f`. No `grep`. You can't even see a log file without opening a support ticket and waiting hours — sometimes days — for someone at Oro to send you a sanitized excerpt of what they think is relevant.

Here's what that debugging cycle actually looks like:

1. Customer reports a bug in production
2. You open an Oro support ticket: "Please share the last 2 hours of logs"
3. Wait 4-8 hours for a response
4. Receive a partial log dump — maybe the right time window, maybe not
5. Realize you need the request context too — open another ticket
6. Wait again
7. Repeat until you finally find the one line that matters

This is not a hypothetical. This is how hundreds of OroCommerce teams debug production issues every day.

### What You're Missing

| What you need | What OroCloud gives you |
|---|---|
| `tail -f /var/log/prod.log` | Nothing. Open a ticket. |
| `grep "OrderID:12345" prod.log` | Nothing. Describe what you want in a ticket. |
| Real-time error alerts | An email from your customer telling you something is broken |
| Log level filtering | Whatever the support engineer decides to paste |
| Request context (URL, IP, user) | Maybe, if you ask specifically |
| Historical log search | Logs are rotated on Oro's schedule, not yours |
| Multi-instance log aggregation | Each support response covers one node at a time |

The OroCloud Console UI has a "log viewer," but it's delayed by minutes (not seconds), doesn't support live tail, has no grep, and can't filter by level. It's a file download tool, not a debugging tool.

## The Solution: Two Complementary Approaches

We built two tools that solve this from different angles. Both write to the same PostgreSQL table (`genaker_log_entry`) and share the same admin UI for viewing.

### 1. PHP Database Log Handler — Captures Logs From Inside Symfony

A Monolog handler that runs alongside the default file handler. When enabled, every log entry that would go to `dev.log` or `prod.log` also gets written to a database table.

```
PHP Request → Monolog → File Handler (always) 
                      → DatabaseLogHandler (optional) → genaker_log_entry table
```

**Key design decisions:**

- **Deferred writes by default.** Log entries are buffered in memory during the request and flushed to the database after the response is sent (`kernel.terminate`). The user never waits for a DB write.
- **Works for all three runtimes.** HTTP requests flush on `kernel.terminate`, CLI commands on `console.terminate`, MQ consumers on `onPostReceived`. No logs are lost because "there's no terminate event."
- **Registered the Oro way.** The handler is declared via `monolog: handlers:` in `app.yml` with `type: service` — not a compiler pass or hack. It gets wrapped by Oro's `DisableDeprecationsHandlerWrapper` automatically, just like every other handler in the system.
- **Off by default.** The handler starts disabled and does nothing until you flip a switch in System Configuration or set `GENAKER_DB_LOG_ENABLED=1` in your environment.

**The problem it doesn't solve:** If PHP crashes (segfault, OOM kill, fatal error during bootstrap), the deferred buffer is lost. The process dies before `kernel.terminate` fires, so those logs never reach the database. For production, you need something that reads the log files directly.

### 2. Go Log Worker — Tails Files Without PHP

A standalone Go binary that watches Monolog log files and ingests entries into the same database table. It runs as a separate process — no PHP involved.

```
PHP writes → prod.log → Go Worker tails → parses → batches → PostgreSQL
```

**Why Go and not PHP?**

A PHP script tailing a file would consume a PHP-FPM worker slot — one of the most precious resources on an OroCommerce server. A Go binary uses ~5 MB of RAM, runs in its own process, and handles multiple log files concurrently with goroutines.

**The in-memory batch grouping is the key optimization.** Before hitting the database, the worker deduplicates entries in memory:

```
Raw batch (100 entries):
  "Connection refused to host db" ×47
  "Slow query detected"           ×30  
  "Auth token expired"            ×23

After GroupBatch():
  3 grouped entries:
    {message: "Connection refused...", count: 47, context: <latest>}
    {message: "Slow query...",        count: 30, context: <latest>}
    {message: "Auth token...",        count: 23, context: <latest>}

DB operation: 3 upserts instead of 100 inserts
```

The database upsert adds the batch count to the existing `occurrence_count`:

```sql
INSERT INTO genaker_log_entry (..., occurrence_count, ...)
VALUES (..., 47, ...)
ON CONFLICT (message_key) DO UPDATE SET
    occurrence_count = genaker_log_entry.occurrence_count + 47,
    created_at = $latest_timestamp
```

In our production tests, a 20 MB log file with 56,705 entries compressed into 85 database rows. That's a 667× reduction.

**Oro config auto-discovery.** The worker finds the database DSN by following Symfony/Oro's `.env` file precedence chain:

1. `ORO_DB_URL` environment variable
2. `.env-app.local`
3. `.env-app.$ORO_ENV.local`
4. `.env-app.$ORO_ENV`
5. `.env-app`

It also strips Oro-specific query parameters (`charset=utf8`, `serverVersion=13.7`) that PostgreSQL doesn't understand, and converts `postgres://` to `postgresql://` for the pgx driver.

## The Admin UI: A Real Log Viewer

Both the PHP handler and the Go worker feed the same table, which is viewable at `/admin/log-entries`:

![Database Log Viewer](Resources/doc/images/db-log-viewer-light.png)

**What you see:**

- **Occurrence count** — color-coded badge showing how many times this log entry fired. Green (<10), yellow (<100), red (100+). One row per unique message, not one row per event.
- **First seen / last seen** — for grouped entries, both timestamps are shown. You can see when an error started and when it last happened.
- **Expandable context** — click any cell to expand the full JSON. Context is truncated to 100 characters by default (configurable).
- **Live tail** — 3-second polling with auto-sort by time. New entries appear at the top. 
- **Level/channel/search filters** — dropdown filters for log level and Monolog channel, plus free-text message search.
- **Column sorting** — click any header to sort ascending/descending.
- **Resizable columns** — drag column borders to resize.
- **Dark/light theme** — toggle with localStorage persistence.
- **Stats bar** — always-visible footer showing: total DB rows, total events (sum of occurrence counts), table size, grouped count.

**What you can do:**

- Filter by ERROR to see only errors
- Search for a specific order ID or customer
- Sort by occurrence count to find the noisiest log entries
- Switch to live tail and watch logs flow in real time
- Clear all entries with one click

All of this works from the OroCommerce admin panel. No SSH. No support ticket. No waiting.

## The OroCloud Visibility Problem — In Detail

OroCloud's restrictions exist for security and stability — that's fair. But the current approach creates a genuine operational gap that costs development teams hours every week.

### What OroCloud Doesn't Give You

**No real-time log access.** When a production error occurs, you're flying blind. The OroCloud Console shows logs with a minutes-long delay and no live tail. By the time you can see the log, the incident might be over — but you still don't know what caused it.

**No log search.** You can download a log file from the Console, but you can't grep it, filter by level, or search for a specific request ID. You download the entire file (often hundreds of MB) and search locally. If the relevant log lines were in a rotated file, you need another download.

**No multi-instance aggregation.** OroCloud runs multiple PHP-FPM instances behind a load balancer. If an error happens on instance 3 but you're looking at instance 1's logs, you'll never find it. There's no unified view.

**No structured logging.** Monolog writes structured data (context arrays, extra fields, stack traces) but the log file flattens everything into text. To search by a specific field (customer ID, order number, API response code), you have to parse the text manually.

**No exception aggregation.** A single misconfiguration can flood the log with thousands of identical errors. Without aggregation, you don't know if you have 1 unique error or 50 — you just see a wall of text.

**Log rotation without warning.** OroCloud rotates logs on its own schedule. The time window you need might have already been purged. You won't know until you open a ticket and get told "those logs are no longer available."

### What This Bundle Fixes

| OroCloud limitation | Our solution |
|---|---|
| No real-time access | Live tail UI with 3-second polling |
| No search/grep | Level filter + channel filter + message search |
| No multi-instance view | All instances write to the same database table |
| No structured logging | Context/extra stored as JSONB, searchable and expandable |
| No exception aggregation | Grouping deduplicates identical entries with occurrence count |
| Log rotation | Database persists independently of file rotation |
| Hours-long support loop | Self-service from admin panel, zero tickets needed |

### The Go Worker Is the OroCloud Answer

On OroCloud, you can't install PHP extensions or modify the Monolog configuration. But you can:

1. Deploy the Go worker as a sidecar container or background process
2. Point it at the log directory (usually `/var/log/oro/`)
3. Give it the `ORO_DB_URL` environment variable

The worker reads log files — it doesn't touch PHP, Symfony, or the application at all. It's a read-only file consumer that writes to a table the admin UI can display.

```yaml
# docker-compose addition for OroCloud-compatible environments
log-worker:
  image: oro-log-worker:latest
  volumes:
    - /var/log/oro:/logs:ro
  environment:
    - ORO_DB_URL=postgres://user:pass@db:5432/oro_db
  restart: unless-stopped
```

## Configuration — Everything Is Toggleable

Every feature can be enabled or disabled from the admin UI (**System → Configuration → Log Viewer & Monitoring**):

| Feature | Default | Notes |
|---|---|---|
| Log Viewer (file browser) | On | Read-only, zero overhead |
| Performance Dashboard | On | Disable to reduce Redis writes |
| SQL Issue Tracker | On | Disable to remove kernel.terminate overhead |
| Browser Console Logger | On | Disable on production |
| Database Log Handler (PHP) | **Off** | Enable for dev/staging |
| Go Log Worker | **Off** | Start the binary to enable |

When the PHP handler is off and the Go worker isn't running, there is zero overhead from the database logging feature. No DB writes, no memory buffering, no hooks.

### Recommended Setup by Environment

| Environment | PHP handler | Go worker | Why |
|---|---|---|---|
| Local dev | On (DEBUG level) | Off | See everything in real time while coding |
| Staging | Off | On (WARNING level) | Catch errors without PHP overhead |
| Production | Off | On (WARNING level) | Zero PHP impact, survives crashes |
| OroCloud | Off | On (WARNING level) | Only option — no PHP config access |

## Performance Numbers

**Go Worker:**
- Parses ~600,000 log lines per second (single core)
- 6 allocations per parsed line
- In-memory grouping: 100 entries grouped in ~53μs
- Binary size: 6 MB
- Memory usage: ~5 MB at runtime

**PHP Handler (deferred mode):**
- Zero impact on response time (writes after response sent)
- ~0.1ms per entry buffered in memory
- One DB transaction per request lifecycle

**Database table with grouping:**
- 56,705 raw log events → 85 rows (667× compression)
- 11 MB table size for 85 grouped rows
- `pg_total_relation_size()` check: ~0.1ms (metadata only)

## Getting Started

### Option 1: PHP Handler (simplest)

```bash
# Enable via environment variable
echo "GENAKER_DB_LOG_ENABLED=1" >> .env-app.local
php bin/console cache:clear
```

Or enable in admin: **System → Configuration → Log Viewer & Monitoring → Database Log Handler → Enable**

### Option 2: Go Worker (recommended for production)

```bash
cd src/Genaker/Bundle/LogViewerBundle/go-worker
go build -o oro-log-worker .
ORO_DB_URL="postgres://user:pass@host:5432/oro_db" ./oro-log-worker -config config.yaml
```

### Option 3: Docker

```bash
docker build -t oro-log-worker src/Genaker/Bundle/LogViewerBundle/go-worker
docker run -d \
  -v /var/log/oro:/oro-ee/var/logs:ro \
  -e ORO_DB_URL="postgres://user:pass@db:5432/oro_db" \
  oro-log-worker
```

### View logs

Navigate to `/admin/log-entries` in the OroCommerce admin panel.

## Source Code

The complete source — PHP bundle, Go worker, tests, Docker config — is open source:

**GitHub:** [github.com/Genaker/OROCommerceLogStatViewer](https://github.com/Genaker/OROCommerceLogStatViewer)

Includes:
- 343 PHP unit tests + 21 integration tests
- 34 Go tests (unit + SQLite integration)
- Dockerfile + docker-compose examples
- Full admin UI with live tail, sorting, filtering, theming
- System Configuration for every setting

---

*Built for OroCommerce teams who are tired of waiting for support tickets to debug their own applications.*
