# Distance Providers

A **distance provider** turns a list of `Coordinate`s into a square
`DistanceMatrix` (kilometers). The TSP solver only ever consumes a
`DistanceMatrix` — it has no idea whether the numbers came from pure math, an
HTTP API, or a database query. This is what lets all five providers be
interchangeable behind `DistanceMatrixProviderInterface`.

All providers implement:

```php
interface DistanceMatrixProviderInterface
{
    /** @param Coordinate[] $coordinates */
    public function build(array $coordinates): DistanceMatrix;
    public function getName(): string;
}
```

Selection happens via `DistanceProviderRegistry::get(?string $name)`:
explicit `$name` (from the HTTP `method` field or CLI `--method`) wins,
otherwise falls back to `genaker_comi_voyager.distance_provider` (System
Configuration), defaulting to `haversine`.

---

## Summary comparison

| | `haversine` | `vincenty` | `osrm` | `google` | `postgis` |
|---|---|---|---|---|---|
| **Location** | `Core/Distance/` | `Core/Distance/` | `Distance/` | `Distance/` | `Distance/` |
| **Computation** | Spherical trig formula | Iterative ellipsoidal (WGS-84) | OSRM `/table` HTTP API | Google Distance Matrix HTTP API | PostgreSQL `ST_DistanceSphere` |
| **External dependency** | None | None | OSRM server (HTTP) | Google Cloud (HTTP, billed) | PostGIS database (separate connection) |
| **Roads/traffic aware** | No (straight line) | No (straight line) | Yes (road network) | Yes (road network + optional traffic) | No (straight line, in DB) |
| **Accuracy vs real driving distance** | Underestimates, often by 20–40%+ | Underestimates, slightly better than haversine | Close to real driving distance | Close to real driving distance, can include live traffic | Same accuracy class as haversine |
| **Latency for N points** | O(N²) in-process, microseconds | O(N²) in-process, ~10x haversine cost | 1 HTTP round-trip for the whole matrix | 1 HTTP round-trip (≤25 points) | O(N²/2) DB round-trips (1 per pair) |
| **Cost** | Free | Free | Free (public demo) / self-host cost | Paid per request | Free (self-hosted Postgres) |
| **Rate limits** | None | None | Public demo server is rate-limited/unreliable | Google API quotas (billed) | Limited by DB connection pool |
| **Max points per call** | Unbounded (practically limited by solver, see ALGORITHMS.md) | Unbounded | Unbounded (single call) | **25** (hard API limit) | Unbounded, but cost grows O(N²) |
| **Failure mode** | Never fails | Never fails | Throws `DistanceProviderUnavailableException` on HTTP/transport error or non-`Ok` response | Throws on missing API key, >25 points, non-`OK` status, or per-element failure | Throws on any `Doctrine\DBAL\Exception` |

---

## `haversine` — great-circle distance (default)

**File:** `Core/Distance/HaversineDistanceMatrixProvider.php`

Computes the great-circle distance between two points assuming Earth is a
perfect sphere of radius 6371 km, using the standard haversine formula:

```
a = sin²(Δlat/2) + cos(lat1)·cos(lat2)·sin²(Δlng/2)
c = 2·atan2(√a, √(1−a))
d = R·c
```

### Pros
- **Zero dependencies, zero latency, zero cost.** Pure math, runs entirely
  in-process.
- Deterministic and side-effect free — ideal for unit tests and as a
  fallback when external services are unavailable.
- Good enough for **relative ranking** of routes when all candidates are
  scaled by roughly the same road/straight-line ratio.
- Scales to arbitrarily large N (only bounded by the solver's own limits).

### Cons
- **Ignores roads, terrain, one-way streets, bridges, water crossings.** A
  straight line over a river or mountain range is not a drivable path.
- Systematically **underestimates** real driving distance — the
  underestimate is *not* uniform (urban grids vs highways vs rural roads
  differ), so it can subtly bias which route is "shortest" when true road
  distances are close.
- Not suitable for ETA/time estimates at all (no speed/road-class data).

### When to use
- Default/fallback method.
- Large batches of addresses where calling an external API for every pair
  would be slow or expensive.
- Internal tools where "roughly nearby" ordering is good enough.

---

## `vincenty` — geodesic distance on WGS-84 ellipsoid

**File:** `Core/Distance/VincentyDistanceMatrixProvider.php`

Solves Vincenty's inverse geodesic problem on the WGS-84 reference ellipsoid
(the same ellipsoid GPS uses), via iterative refinement (up to 200
iterations, converges to `1e-12` radians).

### Pros
- **More accurate than haversine** for long distances, because it accounts
  for Earth's flattening (~0.3% oblateness) rather than assuming a perfect
  sphere. The difference is usually small (<0.5%) but compounds over many
  legs.
- Still pure PHP, free, no external dependencies, deterministic.
- Same interface as haversine — drop-in replacement.

### Cons
- **~10x more CPU per pair** than haversine (iterative trig vs closed-form),
  though still negligible for typical N (microseconds-to-milliseconds total).
- Same fundamental limitation as haversine: **straight-line, not road
  distance**. The ellipsoid correction does not address the roads-vs-line
  gap, which is far larger than the sphere-vs-ellipsoid correction.
- Marginal accuracy gain rarely changes which route ranks first — mostly
  useful when you need geodetically precise distance *values* (e.g. for
  reporting), not just route ordering.

### When to use
- When reported distance *values* (not just ordering) need to be
  geodetically accurate and you cannot call an external API.
- Otherwise, prefer `haversine` (faster, same practical ranking) or a
  road-aware provider (`osrm`/`google`) if accuracy actually matters.

---

## `osrm` — road network via OSRM `/table` API

**File:** `Distance/OsrmDistanceMatrixProvider.php`

Calls `GET {base_url}/table/v1/driving/{lng,lat;lng,lat;...}?annotations=distance`
on an [OSRM](http://project-osrm.org/) server. OSRM's `/table` service
computes a full **N×N matrix in a single request** using contraction
hierarchies — very fast even for dozens of points.

- Default `base_url`: `https://router.project-osrm.org` (public demo).
- Configurable via `genaker_comi_voyager.osrm_base_url`.
- Timeout: 15s. On transport/HTTP error or non-`"Ok"` response, throws
  `DistanceProviderUnavailableException` (logged to the `comivoyager`
  channel).
- **The matrix is asymmetric** (one-way streets and ramps make A→B ≠ B→A).
  The solver detects this (`DistanceMatrix::isSymmetric()`) and uses the
  directed distances throughout — 2-opt re-prices reversed segment edges,
  and a route and its reversal are ranked as distinct alternatives (see
  [ALGORITHMS.md](ALGORITHMS.md)). The same applies to `google`.

### Pros
- **Real driving distances** — follows actual roads, respects one-way
  streets, bridges, etc. Dramatically more accurate than straight-line
  methods for logistics/delivery use cases.
- **Single HTTP call regardless of N** — the `/table` endpoint returns the
  whole matrix at once, so latency doesn't multiply with point count (unlike
  `postgis`'s per-pair queries).
- **Free if self-hosted** — OSRM is open source; once you have a `.osrm`
  extract for your region, there's no per-request cost or external rate
  limit.
- Open data (OpenStreetMap) — no vendor lock-in, no API key.

### Cons
- **Public demo server is not production-grade**: shared, rate-limited, can
  be slow or unavailable, and only covers the OSM "driving" profile globally
  (not always tuned per-region).
- **Self-hosting requires infrastructure**: pre-processing a `.osrm` extract
  for your region(s), running the OSRM backend, keeping map data up to date.
- Distance only — `/table` with `annotations=distance` does **not** return
  ETAs/durations in this implementation (would require
  `annotations=duration` and additional handling).
- No traffic data — distances/times are static, based on road geometry and
  speed profiles baked into the extract.

### When to use
- Production logistics/delivery routing where road accuracy matters and you
  can self-host OSRM.
- Quick prototyping/demos against the public server (with the caveat it may
  be flaky).

---

## `google` — Google Distance Matrix API

**File:** `Distance/GoogleDistanceMatrixProvider.php`

Calls `GET https://maps.googleapis.com/maps/api/distancematrix/json` with
`origins`/`destinations` as a pipe-separated list of all points (full N×N
matrix in one call), requiring `genaker_comi_voyager.google_api_key`.

- **Hard limit: 25 points per request** (`MAX_POINTS`). Throws
  `DistanceProviderUnavailableException` immediately if exceeded — no
  batching/chunking is implemented.
- Throws if the API key is empty, the overall `status` isn't `"OK"`, or any
  individual `element.status` isn't `"OK"` (e.g. `ZERO_RESULTS` for an
  unreachable pair).
- Timeout: 15s.

### Pros
- **Best-in-class road data and (optionally) live traffic** — Google Maps
  has the most comprehensive and frequently-updated road network data
  available.
- **Single API call** for up to 25 points — low latency.
- Mature, well-documented, SLA-backed commercial service.
- Same data source can power the `google` geocoder, so addresses and
  distances come from a consistent map.

### Cons
- **Costs money** — billed per element (origin × destination pair), can add
  up quickly for large or frequent route batches.
- **Hard 25-point ceiling** with no fallback — for >25 stops you must either
  pre-filter, switch providers, or implement batching yourself (not done
  here, since the solver's exact methods only go up to 15 stops anyway —
  see [ALGORITHMS.md](ALGORITHMS.md)).
- Requires managing/rotating an API key, IP/referrer restrictions, and
  monitoring quota usage.
- Subject to Google's terms of service (e.g. caching/storage restrictions on
  Google-derived distance data).

### When to use
- Production scenarios needing the highest-fidelity road distances and/or
  live traffic, where the per-request cost is acceptable and N ≤ 25.
- When the project already pays for Google Maps Platform for other features.

---

## `postgis` — PostgreSQL/PostGIS `ST_DistanceSphere`

**File:** `Distance/PostgisDistanceMatrixProvider.php`

Computes the same **great-circle** distance as `haversine`, but evaluated by
PostgreSQL via PostGIS's `ST_DistanceSphere(ST_MakePoint(lng, lat), ...)`,
over a **separate, dedicated `comivoyager_postgis` DBAL connection** (see
[INSTALLATION.md](INSTALLATION.md)).

For each unordered pair `(i, j)`, runs:

```sql
SELECT ST_DistanceSphere(ST_MakePoint(:lng1, :lat1), ST_MakePoint(:lng2, :lat2))
```

— i.e. **N(N-1)/2 separate round trips** to fill the matrix (the matrix is
symmetric, so each pair is computed once and mirrored).

### Pros
- Useful when coordinates **already live in PostGIS** alongside other
  geospatial data — keeps distance computation co-located with the data and
  consistent with other PostGIS-based queries/reports in the system.
- No external HTTP dependency, no API key, no rate limits — just a Postgres
  connection.
- `ST_DistanceSphere` is a well-tested, standard PostGIS function — accuracy
  is equivalent to `haversine` (same spherical-Earth assumption).
- Self-hosted (the `postgis/postgis` Docker image auto-enables the
  extension) — fully under your control, free.

### Cons
- **Same accuracy ceiling as `haversine`** (straight-line, not road
  distance) — choosing this provider over `haversine` is *not* about
  accuracy, only about where the computation happens.
- **O(N²) database round trips** — for N=15 that's 105 queries; this is the
  slowest provider for larger N (no batched/single-query implementation).
  Network latency to the DB multiplies directly into total request time.
- Adds an **operational dependency**: a second Postgres instance/connection
  must be running and reachable, with PostGIS enabled.
- Throws `DistanceProviderUnavailableException` on *any* `Doctrine\DBAL\Exception`
  (connection refused, timeout, etc.) — a transient DB blip fails the whole
  request.

### When to use
- When the system already has a PostGIS database with relevant location data
  and you want distance computation to live alongside it.
- Small N (the per-pair round-trip cost matters more as N grows).
- **Not** recommended purely as "a database alternative to haversine" —
  `haversine` is strictly cheaper and equally accurate for that case.

---

## Choosing a provider — decision guide

```
Need real road distances?
├── No  → small/medium batches, no external deps     → haversine (default)
│         need slightly better straight-line accuracy → vincenty
│         coordinates already in PostGIS              → postgis
└── Yes → can self-host OSRM                          → osrm
          need traffic-aware / highest fidelity,
          ≤25 points, budget for API cost             → google
```

In all cases the provider can be overridden **per request** (HTTP `method`
field / CLI `--method`), so a UI could let users pick "fast estimate"
(haversine) vs "accurate" (osrm/google) on demand.
