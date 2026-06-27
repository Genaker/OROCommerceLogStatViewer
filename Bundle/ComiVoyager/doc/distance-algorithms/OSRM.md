# OSRM Distance Provider

| | |
|---|---|
| **Name** (`method` / `getName()`) | `osrm` |
| **File** | [`Distance/OsrmDistanceMatrixProvider.php`](../../Distance/OsrmDistanceMatrixProvider.php) |
| **Namespace** | `Genaker\Bundle\ComiVoyager\Distance` |
| **Layer** | Bundle root — Symfony/Oro-aware (HTTP client, `ConfigManager`, `LoggerInterface`) |
| **Default?** | No (opt-in via `genaker_comi_voyager.distance_provider` or per-request `method`) |
| **External calls** | HTTP `GET {base_url}/table/v1/driving/...` |
| **Config** | `genaker_comi_voyager.osrm_base_url` (default `https://router.project-osrm.org`) |
| **Unit tests** | [`Tests/Unit/Distance/OsrmDistanceMatrixProviderTest.php`](../../Tests/Unit/Distance/OsrmDistanceMatrixProviderTest.php) |
| **Installation** | See [../INSTALLATION.md §3](../INSTALLATION.md#3-optional-osrm-road-distance-provider) — Docker ([§3.1](../INSTALLATION.md#31-running-osrm-with-docker-recommended-for-self-hosting)), bare-metal ([§3.2](../INSTALLATION.md#32-running-osrm-without-docker)), or no install at all using the public demo server ([§3.3](../INSTALLATION.md#33-no-installation-at-all-testing-only)). |

---

## 1. What it computes

**Real driving distance** along the road network, using
[OSRM (Open Source Routing Machine)](http://project-osrm.org/) — an open
source, high-performance routing engine built on
[OpenStreetMap](https://www.openstreetmap.org/) data.

Unlike [`haversine`](HAVERSINE.md)/[`vincenty`](VINCENTY.md) (straight-line)
or [`postgis`](POSTGIS.md) (also straight-line), this returns distances that
follow **actual roads** — respecting one-way streets, bridges, highways vs.
local roads, and barriers like rivers/coastlines.

## 2. How OSRM's `/table` service works

OSRM exposes several HTTP services; this provider uses the **Table
service**, which computes a full **N×N distance/duration matrix in a single
request** — critically different from calling a point-to-point routing API
N² times.

### Why a single call for the whole matrix is possible

OSRM pre-processes the road network offline into a **contraction
hierarchy** (or **MLD** — Multi-Level Dijkstra, in newer versions): a
layered graph structure where shortcuts ("contractions") between
far-apart nodes are precomputed. The `/table` endpoint runs many-to-many
shortest-path queries against this structure simultaneously, sharing work
across all source/destination pairs — making it dramatically faster than N²
independent route queries, even for tens or hundreds of points.

### Request format

```php
$coordinatesParam = implode(';', array_map(
    static fn (Coordinate $coordinate) => sprintf('%F,%F', $coordinate->lng, $coordinate->lat),
    $coordinates
));

$response = $this->httpClient->request(
    'GET',
    sprintf('%s/table/v1/driving/%s', $baseUrl, $coordinatesParam),
    [
        'query' => ['annotations' => 'distance'],
        'timeout' => 15,
    ]
);
```

- **Coordinate order is `lng,lat`** (longitude first!) — this is OSRM's
  (and GeoJSON's) convention, the **opposite** of the `Coordinate` model's
  `lat, lng` field order. Getting this backwards is the single most common
  integration bug with OSRM — note the explicit `$coordinate->lng, $coordinate->lat`
  in the `sprintf`.
- `%F` formats floats with full precision and locale-independent `.` decimal
  separator (critical — `%f` under some locales could emit `,` as the
  decimal separator, producing an invalid URL).
- `driving` is the OSRM **routing profile** — alternatives like `walking` or
  `cycling` are not exposed by this bundle (would require a config option
  and a profile-specific OSRM dataset).
- `annotations=distance` requests **distances only**. OSRM can also return
  `duration` (and `speed`) — **not used here**, see §6.
- **15-second timeout** — generous for `/table`, which is fast even for
  larger matrices, but allows for cold-start/network latency to a
  self-hosted instance.

### Response format

```json
{
  "code": "Ok",
  "distances": [
    [0,      343600.5],
    [343600.5, 0]
  ]
}
```

- `distances[i][j]` is in **meters**.
- `code` must be `"Ok"` — any other value (`"NoRoute"`, `"InvalidQuery"`,
  etc.) or a missing/empty `distances` array triggers
  `DistanceProviderUnavailableException`.

### Response handling

```php
$data = $response->toArray();
if (($data['code'] ?? null) !== 'Ok' || empty($data['distances'])) {
    throw new DistanceProviderUnavailableException('OSRM table request did not return distances.');
}

$matrix = [];
foreach ($data['distances'] as $i => $row) {
    foreach ($row as $j => $meters) {
        $matrix[$i][$j] = $i === $j ? 0.0 : ((float) $meters) / self::METERS_PER_KM;
    }
}
```

Note: even though OSRM returns `0` on the diagonal anyway, the code
explicitly forces `$i === $j` to `0.0` for consistency with the other
providers (defensive, in case OSRM ever returned a tiny non-zero
self-distance due to snapping).

## 3. Worked example: London → Paris → Berlin

Per the live smoke test referenced in the project's Phase 2 verification:

| Pair | Haversine (straight-line) | OSRM (driving) | Circuity factor |
|---|---|---|---|
| London ↔ Paris | ~343.6 km | ~620.4 km | ~1.81x |

The large circuity factor here is because **the straight line crosses the
English Channel** — OSRM's road route goes around via the Channel Tunnel
(Eurotunnel) rail shuttle / ferry-adjacent road routing, which OSRM's
"driving" profile may or may not represent accurately depending on how
ferry/rail-shuttle segments are tagged in the underlying OSM data. This is a
good illustration of *why* haversine is unsuitable when water crossings are
involved — see [HAVERSINE.md §6](HAVERSINE.md#6-accuracy-analysis).

## 4. Error handling & failure modes

```php
try {
    $response = $this->httpClient->request(/* ... */);
    // ... parse
} catch (TransportExceptionInterface|ServerExceptionInterface|ClientExceptionInterface $exception) {
    $this->logger->error('OsrmDistanceMatrixProvider error', ['error' => $exception->getMessage()]);

    throw new DistanceProviderUnavailableException(
        'OSRM distance provider is unavailable: ' . $exception->getMessage(),
        0,
        $exception
    );
}
```

| Failure | Caught by | Result |
|---|---|---|
| Network unreachable / DNS failure / timeout | `TransportExceptionInterface` | `DistanceProviderUnavailableException`, logged to `comivoyager` channel |
| OSRM returns 5xx | `ServerExceptionInterface` | same |
| OSRM returns 4xx (e.g. malformed request) | `ClientExceptionInterface` | same |
| OSRM returns 200 but `code !== "Ok"` (e.g. `"NoRoute"` — no path between points, `"InvalidQuery"`) | explicit check | `DistanceProviderUnavailableException`, **not logged** (no `catch` block for this branch — thrown directly) |
| `distances` empty/missing | same explicit check | same |

In `RouteOptimizationController`, any `DistanceProviderUnavailableException`
becomes an HTTP **422 Unprocessable Entity** with the exception message as
`{"error": "..."}`.

### `< 2` coordinates

```php
$size = count($coordinates);
if ($size < 2) {
    return new DistanceMatrix(array_fill(0, $size, array_fill(0, $size, 0.0)));
}
```

For 0 or 1 coordinates, returns a trivial all-zero matrix **without making
any HTTP call** — avoids a pointless request for a degenerate input (in
practice `ComiVoyager::optimize()` already requires ≥2 addresses, so this is
mostly defensive).

## 5. Complexity & performance

| | |
|---|---|
| **HTTP requests for N points** | **1**, regardless of N (the entire matrix in one call) |
| **OSRM-side compute** | Sub-second for typical N (tens to low hundreds of points), thanks to contraction hierarchies/MLD |
| **Network latency** | Dominant cost — 1 round trip to `base_url`, ~15s timeout |
| **PHP-side processing** | O(N²) to copy/convert the response matrix — negligible |

This is the **most request-efficient** of the three external/DB-backed
providers: `google` is also 1 request but capped at 25 points, and `postgis`
makes O(N²) DB round trips. OSRM's single-request, no-hard-limit-on-N design
makes it the best choice for larger point sets when road accuracy matters.

## 6. What's *not* implemented (potential extensions)

- **Duration/ETA**: OSRM's `/table` can return `annotations=duration` (and
  `duration,distance` together) — this provider only requests/parses
  `distance`. Adding ETA support would mean extending
  `DistanceMatrixProviderInterface` (or adding a parallel
  `DurationMatrixProviderInterface`) — a larger architectural change, not
  done here.
- **Routing profile selection**: hardcoded to `driving`. OSRM also supports
  `walking`, `cycling`, and custom profiles if the `.osrm` extract was built
  for them — would need a new config field
  (`genaker_comi_voyager.osrm_profile`).
- **Alternative routes / traffic**: the open-source OSRM `/table` service
  has no live traffic data (that's a key differentiator for
  [`google`](GOOGLE.md)). Distances are static, based on the road network
  and speed profiles baked into the `.osrm` extract at processing time.

## 7. Operational considerations

### Public demo server (default)

`https://router.project-osrm.org` — OSRM project's free public instance.

- ✅ Zero setup — works immediately for testing/prototyping.
- ❌ **Not for production**: shared by everyone using OSRM demos worldwide,
  subject to rate limiting, latency spikes, and occasional downtime, with no
  SLA. The project's own docs explicitly discourage production use.

### Self-hosted

See [INSTALLATION.md §3](../INSTALLATION.md#3-optional-osrm-road-distance-provider)
for Docker and bare-metal setup steps. Key operational points:

- **Map data freshness**: a `.osrm` extract is a point-in-time snapshot of
  OpenStreetMap. New roads, closures, or address changes won't be reflected
  until the extract is regenerated (`osrm-extract` + `osrm-contract`/
  `osrm-partition`+`osrm-customize`).
- **Regional vs. global extracts**: processing a global OSM extract is
  resource-intensive (high memory, long processing time). Most self-hosted
  deployments use a regional extract (e.g. one country/continent) matching
  where the business operates.
- **No API key/billing** — once running, it's entirely self-managed, no
  per-request cost.

## 8. When to use

✅ **Use `osrm` when:**
- Real driving distances matter (logistics, delivery routing) and you can
  either accept the public demo server (testing) or self-host (production).
- You want a **single HTTP call** regardless of how many stops (vs.
  `google`'s 25-point cap or `postgis`'s O(N²) queries).
- No budget for per-request API costs (`google`).

❌ **Avoid `osrm` when:**
- You can't self-host and the public demo server's reliability/rate limits
  are unacceptable for production traffic — consider [`google`](GOOGLE.md)
  instead (paid, SLA-backed).
- You need live traffic-aware ETAs — OSRM `/table` has no traffic data.

## 9. Related

- [GOOGLE.md](GOOGLE.md) — commercial alternative with traffic data, capped
  at 25 points, billed per request.
- [HAVERSINE.md](HAVERSINE.md) — free fallback when OSRM is unreachable.
- [../INSTALLATION.md](../INSTALLATION.md#3-optional-osrm-road-distance-provider) —
  Docker/bare-metal self-hosting steps.
- [../API.md](../API.md) — error response shapes (`422` on
  `DistanceProviderUnavailableException`).
