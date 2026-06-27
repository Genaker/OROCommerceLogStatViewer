# System Configuration Reference

All settings are defined in
`DependencyInjection/Configuration.php` (config tree, under
`genaker_comi_voyager.*`) and exposed in the Oro admin UI via
`Resources/config/oro/system_configuration.yml`:

**System Configuration → Integrations → ComiVoyager Settings**
(group `comivoyager_settings`, icon `fa-road`, priority `100`).

Every field is read at request time via `ConfigManager::get('genaker_comi_voyager.<key>')`
— changes take effect immediately, no cache clear needed (standard Oro
system config behavior).

---

## Fields

### `distance_provider`

| | |
|---|---|
| Config key | `genaker_comi_voyager.distance_provider` |
| Type | `ChoiceType` (string) |
| Default | `haversine` |
| Choices | `haversine`, `vincenty`, `osrm`, `google`, `postgis` |
| Used by | `DistanceProviderRegistry::get()` when no per-request `method` is given |
| Priority | 100 |

Selects the default distance calculation method for
`POST /comivoyager/optimize` and is overridable per-request via the
`method` field. See [DISTANCE_PROVIDERS.md](DISTANCE_PROVIDERS.md) for a full
pros/cons breakdown of each option.

### `geocoder`

| | |
|---|---|
| Config key | `genaker_comi_voyager.geocoder` |
| Type | `ChoiceType` (string) |
| Default | `nominatim` |
| Choices | `nominatim`, `google` |
| Used by | `GeocoderRegistry::get()` when no per-request `geocoder` is given |
| Priority | 90 |

Selects the default geocoder for resolving free-text `"address"` fields.
See [GEOCODING.md](GEOCODING.md).

### `osrm_base_url`

| | |
|---|---|
| Config key | `genaker_comi_voyager.osrm_base_url` |
| Type | `UrlType` |
| Default | `https://router.project-osrm.org` |
| Constraints | `Url`, `NotBlank` |
| Resettable | yes |
| Used by | `OsrmDistanceMatrixProvider::build()` |
| Priority | 80 |

Base URL of the OSRM server used by the `osrm` distance provider. The
default points at OSRM's **public demo server** — fine for testing, **not**
recommended for production (no SLA, shared rate limits). Point this at a
self-hosted OSRM instance for production use. See
[INSTALLATION.md](INSTALLATION.md#3-optional-osrm-road-distance-provider).

A **Test Connection** button is shown next to this field. It posts the
current (or, if left unchanged, the stored) URL to
`POST /comivoyager/admin/test-connection/osrm`, which calls `ConnectionTesterService::testOsrm()`
— a small OSRM `/table/v1/driving` request against two fixed Berlin
coordinates — and reports success/failure inline, so a bad URL surfaces here
instead of as a 422 on the first real optimization request.

### `google_api_key`

| | |
|---|---|
| Config key | `genaker_comi_voyager.google_api_key` |
| Type | `OroEncodedPlaceholderPasswordType` (encrypted) |
| Default | `null` |
| Required | no |
| Resettable | yes |
| Used by | `GoogleDistanceMatrixProvider`, `GoogleGeocoder` |
| Priority | 70 |

Shared API key for **both** the `google` distance provider and the `google`
geocoder. Stored encrypted; the form shows a placeholder rather than the raw
key. If empty:
- `GoogleDistanceMatrixProvider::build()` throws
  `DistanceProviderUnavailableException` immediately.
- `GoogleGeocoder::geocode()` logs a warning and returns `null` (surfaces as
  `GeocodingFailedException` from `RouteOptimizationService`).

A **Test Connection** button is shown next to this field. It posts the
current (or, if the placeholder is unchanged, the stored and decrypted) key
to `POST /comivoyager/admin/test-connection/google`, which calls
`ConnectionTesterService::testGoogle()` — a small Google Geocoding API call —
and reports success/failure inline, so an invalid key surfaces here instead
of as a 422 on the first real request.

### `default_route_count`

| | |
|---|---|
| Config key | `genaker_comi_voyager.default_route_count` |
| Type | `IntegerType` |
| Default | `3` |
| Constraints | `NotBlank`, `Range(min: 1, max: 10)` |
| Resettable | yes |
| Used by | `RouteOptimizationService::optimize()` when no per-request `routes` is given |
| Priority | 60 |

How many ranked routes (`routes[]` in the response) are returned by default.
Overridable per-request via the `routes` field. Note: the actual number
returned may be lower if fewer distinct tours exist (see
[ALGORITHMS.md](ALGORITHMS.md#how-top-n-is-assembled-topnroutesolversolve)).

### `enable_geocode_cache`

| | |
|---|---|
| Config key | `genaker_comi_voyager.enable_geocode_cache` |
| Type | `ConfigCheckbox` (boolean) |
| Default | `true` |
| Used by | `GeocoderRegistry::get()` |
| Priority | 50 |

When enabled, the resolved geocoder is wrapped in `CachingGeocoder`, which
stores geocode results in `genaker_comivoyager_geocode_cache` keyed by a
hash of the normalized address text. See
[GEOCODING.md](GEOCODING.md#cachinggeocoder--db-backed-cache-decorator) for
pros/cons. Recommended to leave **enabled**.

### `geocode_cache_ttl_days`

| | |
|---|---|
| Config key | `genaker_comi_voyager.geocode_cache_ttl_days` |
| Type | `IntegerType` |
| Default | `30` |
| Constraints | `NotBlank`, `Range(min: 1, max: 365)` |
| Resettable | yes |
| Used by | `GeocoderRegistry::get()` (passed to `CachingGeocoder`) |
| Priority | 40 |

How long (in days) a cached geocode result is considered "fresh"
(`GeocodeCacheRepository::findFreshByHash()`). After expiry, the address is
re-geocoded on next lookup and the cache row is replaced. Only relevant if
`enable_geocode_cache` is `true`.

### `max_addresses`

| | |
|---|---|
| Config key | `genaker_comi_voyager.max_addresses` |
| Type | `IntegerType` |
| Default | `9` |
| Constraints | `NotBlank`, `Range(min: 2, max: 1000)` |
| Resettable | yes |
| Used by | `RouteOptimizationService::optimize()` |
| Priority | 30 |

Maximum number of addresses accepted in a single
`POST /comivoyager/optimize` request. Requests with more addresses than
this limit fail fast with **HTTP 400** before any geocoding,
distance-matrix, or solver work begins. See
[API.md](API.md#error-responses).

**Why the default is 9 — measured exhaustive-solver cost.** Up to 9
addresses the solver enumerates every possible visiting order, so the best
route *and* every ranked runner-up are provably optimal. That exactness has
an n-factorial price, measured on this codebase (haversine provider,
PHP 8):

| Addresses | Time | Peak memory |
|---|---|---|
| 8 | ~0.2 s | ~67 MB |
| 9 | ~3.4 s | ~425 MB |
| 10 (exhaustive) | ~7 min | ~3.9 GB |

The exhaustive strategy is therefore capped at 9 stops in code
(`TopNRouteSolver::EXACT_LIMIT`). **Raising `max_addresses` above 9 is safe
performance-wise** — 10–15 stops use Held-Karp (exact best route,
milliseconds) and 16+ use fast heuristics — but the ranked alternative
routes are then approximations, no longer guaranteed to be the true
2nd/3rd-best orderings. Also note the Google provider's separate 25-point
per-request ceiling, and that `n` text addresses still mean `n` sequential
geocoding calls (~1/s on Nominatim's public server).

---

## Settings not exposed in the UI

### `ORO_COMIVOYAGER_POSTGIS_DSN` (environment variable)

Not a `genaker_comi_voyager.*` system config field — this is a Symfony
environment variable consumed by Doctrine DBAL, with a default provided by
the bundle itself (`GenakerComiVoyagerExtension::load()`). Only relevant if
`distance_provider` (or per-request `method`) is `postgis`. See
[INSTALLATION.md](INSTALLATION.md#5-optional-postgis-distance-provider-separate-database).

---

## Quick reference table

| Key | Type | Default | Range/Choices |
|---|---|---|---|
| `distance_provider` | string | `haversine` | haversine, vincenty, osrm, google, postgis |
| `geocoder` | string | `nominatim` | nominatim, google |
| `osrm_base_url` | url | `https://router.project-osrm.org` | any valid URL |
| `google_api_key` | string (encrypted) | `null` | — |
| `default_route_count` | int | `3` | 1–10 |
| `enable_geocode_cache` | bool | `true` | — |
| `geocode_cache_ttl_days` | int | `30` | 1–365 |
| `max_addresses` | int | `9` | 2–1000 |
