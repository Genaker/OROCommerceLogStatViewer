# GenakerLogViewerBundle

An OroCommerce admin bundle providing two tools for platform operators:

- **Log Viewer** — browse, search, tail, and manage application log files from the admin UI
- **Performance Dashboard** — real-time server metrics (CPU, memory, top processes) and message-queue throughput, aggregated across all PHP-FPM instances

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
| Load averages | 1 min / 5 min / 15 min CPU load from `/proc/loadavg` |
| Memory breakdown | Total / used / free / available + usage percentage |
| CPU cores | Core count from `/proc/cpuinfo` |
| Top processes | Top-10 processes by CPU/memory (readable accordion, collapsed by default) |
| MQ report | Message-queue consumer throughput from `PerfMqReportExtension` listener |
| Auto-refresh | Configurable polling interval; play/pause control |
| Multi-instance aggregation | Metrics stored in the PSR-6 cache (Redis in production); all instances share a common registry key |

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

Settings are available under **System → Configuration → Platform → Log Viewer**.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `genaker_log_viewer.enabled` | boolean | `true` | Disable on production to prevent log file exposure |
| `genaker_log_viewer.lines_per_page` | integer | — | Default number of lines shown per page (10–1000) |

The log directory and other low-level parameters are set in `Resources/config/services.yml` via constructor injection into `LogFileReader` and `LogFileValidator`.

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

---

## ACL Permissions

Defined in `Resources/config/oro/acls.yml`:

| ACL resource | Label | Grants access to |
|---|---|---|
| `genaker_log_viewer_index` | View Log Files | Log file list and all read-only log actions |
| `genaker_log_viewer_truncate` | Truncate Log Files | Delete and truncate log file actions |
| `genaker_perf_dashboard_index` | View Performance Dashboard | Performance dashboard and metrics API |

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
```

Current test status: **101 unit tests, 302 assertions — all passing**.

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
| Datagrid name | `genaker-log-files-grid` |
| JS module prefix | `genakerlogviewer/js/app/components/` |
