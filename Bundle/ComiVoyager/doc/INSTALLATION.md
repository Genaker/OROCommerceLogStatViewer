# Installation & Docker Setup

GenakerComiVoyagerBundle is a normal Symfony/Oro bundle — once
`src/Genaker/Bundle/ComiVoyager/` is present and registered in
`config/bundles.php` (via `Resources/config/oro/bundles.yml`), it is wired up
automatically. This page covers everything needed to get **all** features
(including the optional PostGIS distance provider) running in the project's
Docker environment.

> Per project convention (see `CLAUDE.md`), the app runs under
> Nginx + PHP-FPM in Docker, served at `http://localhost:8000/`. Do **not**
> run `php -S` or restart the server manually — only `cache:clear` /
> `cache:warmup` are needed after config changes.

---

## Setup matrix — what each approach needs

Pick whichever distance provider(s) / geocoder(s) you want
(see [DISTANCE_PROVIDERS.md](DISTANCE_PROVIDERS.md) /
[GEOCODING.md](GEOCODING.md) for *why* you'd pick one), then follow only the
rows below that apply. Everything else can be skipped.

| Approach | Docker (this repo's `docker-compose.yml`) | Without Docker (bare-metal / existing server) | API key / account needed? |
|---|---|---|---|
| `haversine` / `vincenty` (default) | Nothing extra — works out of the box | Nothing extra — pure PHP, works out of the box | No |
| `nominatim` geocoder (default) | Nothing extra — outbound HTTPS to `nominatim.openstreetmap.org` | Same — just needs outbound internet access from the app server | No |
| `osrm` distance provider | Nothing extra to *try it* (defaults to public demo server). For production, add a self-hosted `osrm` service to `docker-compose.yml` | Either use the public demo server (testing only), or install/run the `osrm-backend` binary yourself and point `osrm_base_url` at it | No |
| `google` distance provider | Nothing — no container needed, just outbound HTTPS | Same — just outbound HTTPS to `maps.googleapis.com` | **Yes** — Google Cloud project + Distance Matrix API key |
| `google` geocoder | Nothing — no container needed | Same | **Yes** — Google Cloud project + Geocoding API key (can be the same key as above) |
| `postgis` distance provider | Add the `comivoyager_postgis` service (already in this repo's `docker-compose.yml`) and run `docker compose up -d comivoyager_postgis` | Install PostgreSQL + PostGIS extension yourself (or point at any existing PostGIS-enabled database), then set `ORO_COMIVOYAGER_POSTGIS_DSN` | No |

In short:
- **No Docker, no API keys, nothing to install** → `haversine`/`vincenty` +
  `nominatim` (the defaults) work immediately.
- **Want road distances without Docker** → either accept the public OSRM demo
  server (default `osrm_base_url`, fine for testing) or pay for/sign up for
  Google Distance Matrix (set `google_api_key`, no install needed).
- **Want PostGIS without Docker** → install PostgreSQL+PostGIS on your own
  server/VM (or use a managed Postgres with the PostGIS extension enabled)
  and point `ORO_COMIVOYAGER_POSTGIS_DSN` at it — see
  [§5.7](#57-running-postgis-without-docker).

---

## 1. Bundle registration

The bundle is auto-registered via `Resources/config/oro/bundles.yml`
(standard Oro bundle discovery). No manual edit to
`config/bundles.php` is required.

## 2. Core features (no extra services needed)

The following work out of the box, with **no Docker changes**:

- `comivoyager:optimize` CLI command
- `haversine` / `vincenty` distance providers (pure PHP)
- `nominatim` geocoder (calls the public OSM API over HTTPS)
- `POST /comivoyager/optimize` HTTP endpoint
- System Configuration screen

Run a cache warmup after pulling the bundle:

```bash
php bin/console cache:clear
php bin/console cache:warmup
php bin/console oro:migration:load --bundles=GenakerComiVoyagerBundle --force
```

The migration creates the `genaker_comivoyager_geocode_cache` table (used by
the geocode cache, see [GEOCODING.md](GEOCODING.md)). It runs against the
**default** Oro database — no separate connection needed.

## 3. Optional: OSRM road-distance provider

`osrm` defaults to the public demo server
(`https://router.project-osrm.org`) — good for testing, **not** for
production (rate-limited, no SLA, single global region). For production:

1. Run a self-hosted OSRM instance (see
   [OSRM backend docs](https://github.com/Project-OSRM/osrm-backend) —
   requires a pre-processed `.osrm` extract for your region).
2. Set the base URL in **System Configuration → Integrations → ComiVoyager
   Settings → OSRM Base URL** (`genaker_comi_voyager.osrm_base_url`), e.g.
   `http://osrm:5000`.
3. If self-hosting in this `docker-compose.yml`, add an `osrm` service and
   point `oro_app` at it via the internal Docker network — no env var is
   required, the base URL is stored in Oro system config.

No additional database or env var is required for OSRM — the only moving
part is the `osrm_base_url` system config value.

### 3.1 Running OSRM with Docker (recommended for self-hosting)

Add a service to `docker-compose.yml`, e.g. for a pre-built `.osrm` extract
mounted from the host:

```yaml
services:
  osrm:
    image: osrm/osrm-backend:latest
    command: osrm-routed --algorithm mld /data/region.osrm
    volumes:
      - ./osrm-data:/data
    ports: ["5001:5000"]
```

Then set **OSRM Base URL** = `http://osrm:5000` (internal Docker network) or
`http://localhost:5001` (from the host).

### 3.2 Running OSRM without Docker

1. Build or install `osrm-backend` natively (see the
   [OSRM backend docs](https://github.com/Project-OSRM/osrm-backend) for
   your OS — it's a C++ project, typically built from source or installed
   via package managers on Linux).
2. Pre-process a `.osrm` extract for your region with `osrm-extract` +
   `osrm-contract` (or `osrm-partition`/`osrm-customize` for MLD).
3. Run `osrm-routed --algorithm mld region.osrm` — by default it listens on
   port `5000`.
4. Set **OSRM Base URL** = `http://<host>:5000` in System Configuration. No
   API key, env var, or app-server changes needed.

### 3.3 No installation at all (testing only)

Leave `osrm_base_url` at its default
(`https://router.project-osrm.org`) and select `osrm` as the distance
provider/method. Works immediately, but is the public OSM demo server —
shared, rate-limited, no SLA. Do not rely on it for production.

## 4. Optional: Google providers (Distance Matrix + Geocoding)

Both `google` distance provider and `google` geocoder share one setting:

- **System Configuration → Integrations → ComiVoyager Settings → Google API
  Key** (`genaker_comi_voyager.google_api_key`)

This is **identical whether or not you use Docker** — there is no
container, no `docker-compose.yml` change, and no environment variable. The
key is stored encrypted in the Oro database via the admin UI.

### 4.1 Account / API key setup (required, one-time)

1. Go to the [Google Cloud Console](https://console.cloud.google.com/) and
   create (or select) a project.
2. Enable the APIs you need under **APIs & Services → Library**:
   - **Distance Matrix API** — required for the `google` distance provider.
   - **Geocoding API** — required for the `google` geocoder.
3. Go to **APIs & Services → Credentials → Create Credentials → API key**.
4. **Restrict the key** (strongly recommended):
   - Application restriction: by server IP (if your app server has a static
     IP) — choose "IP addresses" and add your server's outbound IP.
   - API restriction: limit to "Distance Matrix API" and/or "Geocoding API"
     only.
5. Ensure **billing is enabled** on the project — both APIs are billed per
   request and will fail (non-`OK` status) on a project without billing,
   even within the free tier.

### 4.2 Configure the bundle

1. In Oro admin: **System Configuration → Integrations → ComiVoyager
   Settings → Google API Key** — paste the key from step 4.1
   (stored via `OroEncodedPlaceholderPasswordType`, shown as a placeholder
   afterwards).
2. Set **Distance Provider** = `google` and/or **Geocoder** = `google` (or
   leave the system default as-is and pass `"method": "google"` /
   `"geocoder": "google"` per-request).
3. No `cache:clear`/`cache:warmup` needed — system config changes apply
   immediately.

### 4.3 Verifying

Run a request with `"method": "google"` against 2–3 known coordinates (see
[API.md](API.md)). A `DistanceProviderUnavailableException`
("requires an API key" / non-`OK` status) usually means the key is missing,
the relevant API isn't enabled, or billing isn't enabled on the project —
check `var/logs/comivoyager.log`.

## 5. Optional: PostGIS distance provider (separate database)

The `postgis` provider computes great-circle distances via PostgreSQL's
`ST_DistanceSphere`, using a **dedicated, non-default Postgres connection**
(`comivoyager_postgis`) — separate from the main Oro database.

### 5.1 Self-contained bundle configuration

Everything is registered **from inside the bundle** — no edits to
`config/doctrine.yml` or `config/config.yml` are required. The bundle's
`GenakerComiVoyagerExtension` (`DependencyInjection/GenakerComiVoyagerExtension.php`):

- Registers a Doctrine DBAL connection named `comivoyager_postgis` via
  `prependExtensionConfig('doctrine', ...)`:

  ```php
  'dbal' => [
      'connections' => [
          'comivoyager_postgis' => [
              'url' => '%env(ORO_COMIVOYAGER_POSTGIS_DSN)%',
              'server_version' => '17',
          ],
      ],
  ],
  ```

- Provides a default for the `ORO_COMIVOYAGER_POSTGIS_DSN` env var (only if
  not already set elsewhere), pointing at the `comivoyager_postgis` Docker
  Compose service:

  ```
  postgresql://comivoyager:comivoyager@comivoyager_postgis:5432/comivoyager
  ```

This produces a Doctrine DBAL service `doctrine.dbal.comivoyager_postgis_connection`,
which `PostgisDistanceMatrixProvider` consumes directly (raw `Connection`,
no ORM entity manager — this is a DBAL-only connection).

### 5.2 Docker Compose service (infrastructure — must be added manually)

`docker-compose.yml` includes a `comivoyager_postgis` service using the
official PostGIS image (which auto-runs `CREATE EXTENSION postgis` on first
boot — no manual SQL needed):

```yaml
services:
  comivoyager_postgis:
    image: postgis/postgis:17-3.5-alpine
    ports: ["5433:5432"]
    labels:
      com.symfony.server.service-prefix: ORO_COMIVOYAGER_POSTGIS
    environment:
      POSTGRES_USER: comivoyager
      POSTGRES_DB: comivoyager
      POSTGRES_PASSWORD: comivoyager
    volumes:
      - comivoyager_postgis:/var/lib/postgresql/data
    healthcheck:
      test: "pg_isready -U$${POSTGRES_USER} -d$${POSTGRES_DB}"
      interval: 5s
      timeout: 30s
      start_period: 40s
    restart: on-failure

  oro_app:
    depends_on:
      - comivoyager_postgis
      # ...other services

volumes:
  comivoyager_postgis: {}
```

### 5.3 Bring up the database

```bash
docker compose up -d comivoyager_postgis
```

The default DSN (`postgresql://comivoyager:comivoyager@comivoyager_postgis:5432/comivoyager`)
matches this compose service exactly, so no `.env` changes are needed in the
default Docker setup.

### 5.4 Overriding the DSN (e.g. managed Postgres in production)

To point at a different PostGIS instance (e.g. a managed RDS/Cloud SQL
instance with PostGIS enabled), set the environment variable
`ORO_COMIVOYAGER_POSTGIS_DSN` for the `oro_app` container/process — it
overrides the bundle's built-in default:

```
ORO_COMIVOYAGER_POSTGIS_DSN=postgresql://user:pass@my-postgis-host:5432/mydb
```

Then:

```bash
php bin/console cache:clear
```

### 5.5 Enable the provider

Set **System Configuration → Integrations → ComiVoyager Settings → Distance
Provider** = `postgis`, or pass `"method": "postgis"` per-request via the
HTTP API / `--method=postgis` on the CLI.

### 5.6 Verifying the connection

```bash
php bin/console debug:container --parameters | grep -i comivoyager_postgis
php bin/console debug:container | grep -i comivoyager_postgis
```

Expected output includes:

```
env(ORO_COMIVOYAGER_POSTGIS_DSN)   postgresql://comivoyager:comivoyager@comivoyager_postgis:5432/comivoyager
.Doctrine\DBAL\Connection $comivoyager_postgisConnection   alias for "doctrine.dbal.comivoyager_postgis_connection"
```

### 5.7 Running PostGIS without Docker

If the app doesn't run in this repo's Docker setup (e.g. deployed directly
on a VM, or using a managed database), you don't need the
`comivoyager_postgis` Compose service at all — only a reachable
PostGIS-enabled Postgres database and the `ORO_COMIVOYAGER_POSTGIS_DSN`
env var. The bundle code (DBAL connection, parameter default) is unchanged
either way.

**Option A — install PostgreSQL + PostGIS on the existing server:**

```bash
# Debian/Ubuntu example
sudo apt-get install postgresql postgresql-contrib postgis postgresql-17-postgis-3

sudo -u postgres psql -c "CREATE ROLE comivoyager LOGIN PASSWORD 'comivoyager';"
sudo -u postgres psql -c "CREATE DATABASE comivoyager OWNER comivoyager;"
sudo -u postgres psql -d comivoyager -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

**Option B — use an existing/managed Postgres instance:**

Most managed providers (RDS, Cloud SQL, Azure Database for PostgreSQL,
Supabase, etc.) support PostGIS as an enable-able extension — run
`CREATE EXTENSION IF NOT EXISTS postgis;` once on the target database (needs
superuser or the managed-equivalent privilege).

**Either way, set the DSN** as an environment variable for the PHP
process/container running the app (e.g. in `.env.local`, systemd unit, or
your process manager's environment config):

```
ORO_COMIVOYAGER_POSTGIS_DSN=postgresql://comivoyager:comivoyager@127.0.0.1:5432/comivoyager
```

Then `php bin/console cache:clear` and verify with the commands in
[§5.6](#56-verifying-the-connection). No API key or third-party account is
needed for `postgis` — it's entirely self-hosted/self-managed.

---

## 6. Migrations

```bash
# Just this bundle:
php bin/console oro:migration:load --bundles=GenakerComiVoyagerBundle --force

# Or as part of a full platform update (run with caution — affects all bundles):
php bin/console oro:platform:update --force
```

The migration only touches the **default** Oro database (the
`genaker_comivoyager_geocode_cache` table). The PostGIS database needs no
migration — `postgis/postgis` images create the `postgis` extension
automatically, and `PostgisDistanceMatrixProvider` queries existing
coordinates without any bundle-owned tables there.

## 7. Logging

The bundle logs to a dedicated Monolog channel `comivoyager`, written to
`var/logs/comivoyager.log` (registered via `prepend()` in
`GenakerComiVoyagerExtension`). Check this file first when diagnosing
provider/geocoder failures.

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `cache:warmup` fails mentioning `comivoyager_postgis` | Connection misconfigured | Check `env(ORO_COMIVOYAGER_POSTGIS_DSN)` via `debug:container --parameters` |
| `postgis` provider throws `DistanceProviderUnavailableException` | DB container not running | `docker compose up -d comivoyager_postgis` and check `docker compose ps` |
| `osrm` provider throws `DistanceProviderUnavailableException` | Public demo server down/rate-limited | Configure a self-hosted OSRM base URL |
| `google` provider/geocoder returns "requires an API key" / null | `genaker_comi_voyager.google_api_key` empty | Set the key in System Configuration |
| HTTP `POST /comivoyager/optimize` redirects to login | Route is `frontend: true` and ACL-protected | Authenticate as a storefront customer user with the `genaker_comivoyager_optimize` permission |
| `GeocodingFailedException` for a text address | Geocoder returned no result | Check spelling/format, try the `google` geocoder, check `var/logs/comivoyager.log` |
