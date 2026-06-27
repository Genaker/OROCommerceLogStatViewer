# Google Distance Matrix Provider

| | |
|---|---|
| **Name** (`method` / `getName()`) | `google` |
| **File** | [`Distance/GoogleDistanceMatrixProvider.php`](../../Distance/GoogleDistanceMatrixProvider.php) |
| **Namespace** | `Genaker\Bundle\ComiVoyager\Distance` |
| **Layer** | Bundle root — Symfony/Oro-aware (HTTP client, `ConfigManager`, `LoggerInterface`) |
| **Default?** | No (opt-in via `genaker_comi_voyager.distance_provider` or per-request `method`) |
| **External calls** | HTTP `GET https://maps.googleapis.com/maps/api/distancematrix/json` (billed) |
| **Config** | `genaker_comi_voyager.google_api_key` (required, shared with [GoogleGeocoder](../GEOCODING.md)) |
| **Hard limit** | **25 points per request** (`MAX_POINTS`) |
| **Unit tests** | none dedicated (covered indirectly via `DistanceProviderRegistryTest`); HTTP behavior is analogous to [`OsrmDistanceMatrixProviderTest`](../../Tests/Unit/Distance/OsrmDistanceMatrixProviderTest.php) pattern |
| **Installation** | See [../INSTALLATION.md §4](../INSTALLATION.md#4-optional-google-providers-distance-matrix--geocoding) — Google Cloud project, billing, and API key setup ([§4.1](../INSTALLATION.md#41-account--api-key-setup-required-one-time)), bundle configuration ([§4.2](../INSTALLATION.md#42-configure-the-bundle)). |

---

## 1. What it computes

**Real driving distance** (and optionally travel time, though only distance
is used here) via the
[Google Distance Matrix API](https://developers.google.com/maps/documentation/distance-matrix/overview)
— Google Maps' road network data, the same data powering Google Maps
navigation, optionally including **live/historical traffic**.

## 2. Request format

```php
private const BASE_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';
private const MAX_POINTS = 25;
private const METERS_PER_KM = 1000.0;

$points = implode('|', array_map(
    static fn (Coordinate $coordinate) => sprintf('%F,%F', $coordinate->lat, $coordinate->lng),
    $coordinates
));

$response = $this->httpClient->request('GET', self::BASE_URL, [
    'query' => [
        'origins' => $points,
        'destinations' => $points,
        'key' => $apiKey,
    ],
    'timeout' => 15,
]);
```

- **Coordinate order is `lat,lng`** — the **opposite** of OSRM's `lng,lat`
  convention (see [OSRM.md §2](OSRM.md#2-how-osrms-table-service-works)).
  Google's API uses `lat,lng` throughout. This is the correct order here —
  `sprintf('%F,%F', $coordinate->lat, $coordinate->lng)`.
- **Same point list used for both `origins` and `destinations`** — this
  produces the full **N×N matrix** in a single request (an "all pairs"
  query), matching the shape needed by `DistanceMatrix`.
- `key` — the configured `genaker_comi_voyager.google_api_key`.
- 15-second timeout, same as OSRM.

### Pre-flight checks (before any HTTP call)

```php
$size = count($coordinates);
if ($size < 2) {
    return new DistanceMatrix(array_fill(0, $size, array_fill(0, $size, 0.0)));
}

if ($size > self::MAX_POINTS) {
    throw new DistanceProviderUnavailableException(sprintf(
        'Google Distance Matrix provider supports at most %d points per request, %d given.',
        self::MAX_POINTS,
        $size
    ));
}

$apiKey = (string) $this->configManager->get('genaker_comi_voyager.google_api_key');
if ($apiKey === '') {
    throw new DistanceProviderUnavailableException('Google Distance Matrix provider requires an API key.');
}
```

Three checks happen **before** any network call — `< 2` points (trivial
matrix, no call needed), `> 25` points (hard API limit, fails fast), and
empty API key (fails fast with a clear message rather than letting Google
return an authentication error).

## 3. The 25-point limit — why it exists and its implications

### Why 25?

This is a **hard limit of the Google Distance Matrix API itself**: a request
may contain at most 25 origins **and** 25 destinations, and the product of
`origins × destinations` must not exceed 100 elements *for the free/standard
tier* (the 25×25=625 combination would itself exceed 100 — in practice
Google's documented limits are more nuanced and tier-dependent, but **25 per
dimension** is the commonly-cited ceiling this implementation uses as a
simple, conservative single check).

### Why no batching/chunking is implemented

A naive "split into batches of 25" approach for an N×N matrix would require
`⌈N/25⌉²` requests (e.g. N=50 → 4 requests of 25×25 each), each potentially
hitting the 100-element product cap depending on tier, and each consuming
separate billing quota. This bundle deliberately does **not** implement this:

- The TSP solver's **exact methods only handle N ≤ 15**
  (`HELD_KARP_LIMIT` in `TopNRouteSolver` — see
  [../ALGORITHMS.md](../ALGORITHMS.md)). For N > 15, results are heuristic
  anyway, so the *value* of perfectly accurate distances for 50+ points is
  lower relative to the cost/complexity of implementing batching.
- For N ≤ 25 (which covers the solver's exact-and-near-exact range plus
  some margin), a single request suffices — **simplicity over premature
  generality** (see project conventions: don't build for hypothetical future
  requirements).

**If you need > 25 points with Google-quality distances**, options are:
1. Pre-cluster addresses into groups of ≤25 (e.g. by region) and solve each
   cluster separately — but this changes the *problem*, not just the
   distance provider.
2. Switch to [`osrm`](OSRM.md), which has no point-count limit and returns
   the full matrix in one call regardless of N.
3. Implement batching yourself (architectural change, not currently
   supported).

## 4. Response format & per-element status

```json
{
  "status": "OK",
  "rows": [
    {
      "elements": [
        {"status": "OK", "distance": {"value": 343600, "text": "343.6 km"}, "duration": {"value": 12345, "text": "3 hours 25 mins"}},
        {"status": "OK", "distance": {"value": 688100, "text": "688 km"}, ...}
      ]
    },
    { "elements": [ ... ] }
  ]
}
```

```php
$data = $response->toArray();
if (($data['status'] ?? null) !== 'OK' || empty($data['rows'])) {
    throw new DistanceProviderUnavailableException(sprintf(
        'Google Distance Matrix request failed with status "%s".',
        $data['status'] ?? 'UNKNOWN'
    ));
}

$matrix = [];
foreach ($data['rows'] as $i => $row) {
    foreach ($row['elements'] as $j => $element) {
        if ($i === $j) {
            $matrix[$i][$j] = 0.0;
            continue;
        }

        if (($element['status'] ?? null) !== 'OK') {
            throw new DistanceProviderUnavailableException(sprintf(
                'Google Distance Matrix could not resolve the distance between point %d and %d.',
                $i,
                $j
            ));
        }

        $matrix[$i][$j] = ((float) $element['distance']['value']) / self::METERS_PER_KM;
    }
}
```

### Two levels of status checking

Google's Distance Matrix API has **two independent status fields**:

1. **Top-level `status`** — covers the request as a whole (`OK`,
   `INVALID_REQUEST`, `OVER_QUERY_LIMIT`, `REQUEST_DENIED`,
   `UNKNOWN_ERROR`, etc.). If not `"OK"`, the entire request failed —
   throws immediately with the status code in the message.

2. **Per-element `status`** — even when the top-level request succeeds
   (`"OK"`), **individual origin/destination pairs can fail independently**
   (most commonly `"ZERO_RESULTS"` — no route exists between that pair,
   e.g. islands with no bridge/ferry in Google's data, or
   `"NOT_FOUND"` for unresolvable coordinates).

   This implementation treats **any** non-`"OK"` element as fatal for the
   *entire* matrix — throws `DistanceProviderUnavailableException`
   immediately, even if only one of N² pairs failed. There's no
   "exclude unreachable pairs and continue" fallback. This is a conservative
   choice: a `DistanceMatrix` with missing/undefined cells would break the
   solver's assumptions (every cell must have a valid distance).

- `distance.value` is in **meters** (same unit as OSRM), converted via
  `METERS_PER_KM = 1000.0`.
- `duration` is present in the response but **not used** — same omission as
  OSRM (see [OSRM.md §6](OSRM.md#6-whats-not-implemented-potential-extensions)).
  Google's `duration_in_traffic` (requires `departure_time=now` and a paid
  tier feature) is also not requested/used.

## 5. Error handling & failure modes

| Failure | Where caught | Result |
|---|---|---|
| `> 25` points | pre-flight check | `DistanceProviderUnavailableException`, **before** any HTTP call |
| Empty `google_api_key` | pre-flight check | `DistanceProviderUnavailableException`, **before** any HTTP call |
| Network/timeout/5xx/4xx | `TransportExceptionInterface\|ServerExceptionInterface\|ClientExceptionInterface` | logged to `comivoyager` channel, then `DistanceProviderUnavailableException` |
| Top-level `status !== "OK"` | explicit check | `DistanceProviderUnavailableException` with the status string, **not separately logged** |
| Any element `status !== "OK"` | explicit check | `DistanceProviderUnavailableException` naming the failing pair, **not separately logged** |

In `RouteOptimizationController`, all of these surface as HTTP **422
Unprocessable Entity**.

## 6. Cost & quota considerations

- **Billed per element** (origin × destination pair), not per request. A
  full N×N matrix costs **N² elements** (including the diagonal, though
  Google may not charge for `origin === destination` pairs — verify current
  pricing).
- For N=10, that's 100 elements per optimization request; for N=25 (the
  cap), **625 elements** — check current
  [Distance Matrix API pricing](https://developers.google.com/maps/documentation/distance-matrix/usage-and-billing)
  before enabling this in a high-traffic endpoint.
- **No caching of distance results** — unlike geocoding (see
  [../GEOCODING.md](../GEOCODING.md)'s `CachingGeocoder`), there is no
  distance-matrix cache. Every `POST /comivoyager/optimize` request with
  `"method": "google"` makes a fresh billed API call, even for identical
  coordinate sets. If repeated identical requests are expected, consider
  caching at the `RouteOptimizationService` or controller layer (not
  currently implemented).
- **Billing must be enabled** on the Google Cloud project — an
  unbilled/quota-exceeded project returns `OVER_QUERY_LIMIT` or
  `REQUEST_DENIED`, surfacing as `DistanceProviderUnavailableException`.

## 7. Complexity & performance

| | |
|---|---|
| **HTTP requests for N points (N ≤ 25)** | **1** |
| **HTTP requests for N > 25** | N/A — throws before any call |
| **Network latency** | 1 round trip, 15s timeout |
| **Billing cost** | O(N²) elements per request |
| **PHP-side processing** | O(N²) to convert response — negligible |

## 8. When to use

✅ **Use `google` when:**
- N ≤ 25 and the highest-fidelity road data (and optionally traffic
  patterns baked into Google's models) is worth the per-request cost.
- The project already integrates Google Maps Platform elsewhere (shared API
  key/billing).
- SLA-backed reliability matters more than cost.

❌ **Avoid `google` when:**
- N > 25 — fails immediately, no fallback.
- Cost-sensitive or high-volume — every request is billed, with no result
  caching.
- No Google Cloud account/billing setup is feasible — use
  [`osrm`](OSRM.md) (free, self-hostable) instead.

## 9. Related

- [OSRM.md](OSRM.md) — free/self-hosted alternative, no point-count limit,
  similar single-request design.
- [../GEOCODING.md](../GEOCODING.md) — the `google` geocoder shares the same
  `google_api_key` setting.
- [../INSTALLATION.md §4](../INSTALLATION.md#4-optional-google-providers-distance-matrix--geocoding) —
  step-by-step Google Cloud project/API key/billing setup.
- [../API.md](../API.md) — error response shapes (`422` on
  `DistanceProviderUnavailableException`).
