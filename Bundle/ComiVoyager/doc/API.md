# API & CLI Reference

## HTTP API

### `POST /comivoyager/optimize`

- **File**: `Controller/RouteOptimizationController.php`
- **Routing**: `Resources/config/oro/routing.yml` — `genaker_comivoyager_optimize`,
  `options.frontend: true` (storefront route, not back-office)
- **ACL**: `Resources/config/oro/acls.yml` — `genaker_comivoyager_optimize`
  (`type: action`, `group_name: commerce`, `frontend: true`), enforced via
  `#[AclAncestor('genaker_comivoyager_optimize')]`
- **Auth**: requires an authenticated **frontend (storefront) session** with
  the above ACL granted. Unauthenticated requests are redirected to login —
  this is expected, not a bug.

#### Request flow

```mermaid
sequenceDiagram
    participant Client as Storefront client
    participant Controller as RouteOptimizationController
    participant Service as RouteOptimizationService
    participant Geo as GeocoderRegistry
    participant Dist as DistanceProviderRegistry
    participant Core as ComiVoyager Core
    participant Solver as TopNRouteSolver

    Client->>Controller: POST /comivoyager/optimize<br/>{addresses, method, geocoder, routes, ...}
    Controller->>Service: optimize(rawAddresses, distanceProvider, geocoder, routes, options)

    opt address has no lat/lng
        Service->>Geo: get(geocoder).geocode(address)
        Geo-->>Service: Coordinate
    end

    Service->>Dist: get(distanceProvider)
    Dist-->>Service: DistanceMatrixProviderInterface
    Service->>Core: optimize(addresses, routes, options)
    Core->>Dist: provider.build(coordinates)
    Dist-->>Core: DistanceMatrix
    Core->>Solver: solve(addresses, matrix, count, options)
    Solver-->>Core: RouteCollection
    Core-->>Service: RouteCollection
    Service-->>Controller: RouteCollection
    Controller-->>Client: 200 JSON {routes: [...]}
```

`method`/`geocoder` select the provider/geocoder for *this request only*
(via the registries shown above); when omitted, the registries fall back to
the `genaker_comi_voyager.distance_provider` / `.geocoder` system config
defaults. The CLI (`comivoyager:optimize`) follows the same
`Core::optimize()` path but skips `Controller`/`Service`/geocoding —
addresses must already have `lat`/`lng`.

#### Request body

```json
{
  "addresses": [
    {"label": "Customer A", "lat": 51.5074, "lng": -0.1278},
    {"label": "Customer B", "address": "10 Rue de Rivoli, Paris, France"},
    {"label": "Customer C", "lat": 52.5200, "lng": 13.4050}
  ],
  "method": "haversine",
  "geocoder": "nominatim",
  "routes": 3,
  "returnToStart": false,
  "depotIndex": null
}
```

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `addresses` | array of objects | **yes** | — | Min 2, max `genaker_comi_voyager.max_addresses` (default 9; larger values trade away exact runner-up routes — see [CONFIGURATION.md](CONFIGURATION.md#max_addresses)) entries (see [Errors](#errors)) |
| `addresses[].label` | string | no | `"Address {n}"` | Display label, echoed back in `stops[].address.label` |
| `addresses[].lat`, `addresses[].lng` | number/numeric string | no* | — | If present, used directly (no geocoding). Must be numeric, `lat` ∈ [-90, 90], `lng` ∈ [-180, 180] |
| `addresses[].address` | string | no* | — | Free-text address; geocoded if `lat`/`lng` absent |
| `method` | string | no | system config `genaker_comi_voyager.distance_provider` (default `haversine`) | One of `haversine`, `vincenty`, `osrm`, `google`, `postgis` — see [DISTANCE_PROVIDERS.md](DISTANCE_PROVIDERS.md) |
| `geocoder` | string | no | system config `genaker_comi_voyager.geocoder` (default `nominatim`) | One of `nominatim`, `google` — see [GEOCODING.md](GEOCODING.md) |
| `routes` | int | no | system config `genaker_comi_voyager.default_route_count` (default `3`) | Number of ranked routes to return |
| `returnToStart` | bool | no | `false` | Treat as a closed loop (return to stop 0 / depot) |
| `depotIndex` | int\|null | no | `null` | 0-based index of the address that must be the route's start |

\* Each address entry must have **either** `lat`+`lng` **or** `address`.

#### Response body (200 OK)

```json
{
  "routes": [
    {
      "rank": 1,
      "isShortest": true,
      "totalDistanceKm": 1031.7,
      "totalStops": 3,
      "averageLegKm": 515.85,
      "longestLegKm": 620.4,
      "deltaFromBestKm": 0.0,
      "stops": [
        {
          "sequence": 1,
          "addressLabel": "Customer A",
          "coordinate": {"lat": 51.5074, "lng": -0.1278},
          "legDistanceKm": null,
          "cumulativeDistanceKm": 0,
          "isStart": true,
          "isEnd": false
        },
        {
          "sequence": 2,
          "addressLabel": "Customer B",
          "coordinate": {"lat": 48.8566, "lng": 2.3522},
          "legDistanceKm": 343.6,
          "cumulativeDistanceKm": 343.6,
          "isStart": false,
          "isEnd": false
        },
        {
          "sequence": 3,
          "addressLabel": "Customer C",
          "coordinate": {"lat": 52.5200, "lng": 13.4050},
          "legDistanceKm": 688.1,
          "cumulativeDistanceKm": 1031.7,
          "isStart": false,
          "isEnd": true
        }
      ],
      "legs": [
        {"fromIndex": 0, "toIndex": 1, "distanceKm": 343.6, "cumulativeDistanceKm": 343.6},
        {"fromIndex": 1, "toIndex": 2, "distanceKm": 688.1, "cumulativeDistanceKm": 1031.7}
      ]
    }
  ],
  "shortestIndex": 0,
  "requestedCount": 3
}
```

| Field | Meaning |
|---|---|
| `routes[]` | Ranked routes, `rank` 1 = shortest. May contain fewer than `requestedCount` if fewer distinct tours exist. |
| `routes[].totalDistanceKm` | Sum of all leg distances |
| `routes[].totalStops` | Number of stops (includes the repeated start stop if `returnToStart`) |
| `routes[].averageLegKm` | `totalDistanceKm / count(legs)` |
| `routes[].longestLegKm` | Longest single leg |
| `routes[].deltaFromBestKm` | `totalDistanceKm - routes[0].totalDistanceKm` (0 for rank 1) |
| `routes[].stops[].addressLabel` / `coordinate` | The stop's `Address::label` and `{lat, lng}` |
| `routes[].stops[].isStart` / `isEnd` | First/last stop flags. `isEnd` is `false` on the start stop if `returnToStart` adds a closing stop |
| `routes[].stops[].legDistanceKm` / `cumulativeDistanceKm` | Distance and running total from the previous stop; `legDistanceKm` is `null` and `cumulativeDistanceKm` is `0` for the first stop |
| `shortestIndex` | Index into `routes[]` of the shortest route (always `0`, since routes are pre-sorted) |
| `requestedCount` | Echoes the resolved `routes` count used for solving |

#### Errors

| HTTP status | Condition | Example body |
|---|---|---|
| `400 Bad Request` | Invalid JSON body | `{"error": "Invalid JSON: Syntax error"}` |
| `400 Bad Request` | Missing/non-array `addresses` | `{"error": "Field \"addresses\" is required and must be an array."}` |
| `400 Bad Request` | An address entry is not an object, or has neither `lat`/`lng` nor `address` | `{"error": "Address at position 1 must have either \"lat\"/\"lng\" or a text \"address\"."}` |
| `400 Bad Request` | `lat`/`lng` present but not numeric (e.g. a typo like `"abc"`) | `{"error": "Address at position 1 has non-numeric \"lat\"/\"lng\"."}` |
| `400 Bad Request` | `lat`/`lng` out of range (`lat` ∉ [-90, 90] or `lng` ∉ [-180, 180]) | `{"error": "..."}` (from `Coordinate`'s constructor) |
| `400 Bad Request` | Fewer than 2 addresses | `{"error": "At least 2 addresses are required, 1 given."}` |
| `400 Bad Request` | More than `max_addresses` (default 9) addresses | `{"error": "Too many addresses: 50 given, maximum is 9."}` |
| `422 Unprocessable Entity` | Geocoding failed for a text address | `{"error": "Could not geocode address at position 1: \"...\"."}` |
| `422 Unprocessable Entity` | Distance provider unavailable (HTTP/DB error, missing API key, unknown provider, etc.) | `{"error": "OSRM distance provider is unavailable: ..."}` |
| `302 Found` (redirect to login) | Not authenticated / missing ACL | — |

---

## CLI: `comivoyager:optimize`

- **File**: `Command/OptimizeRouteCommand.php`
- **Scope**: only `haversine`/`vincenty` (pure-PHP) providers — does **not**
  go through `DistanceProviderRegistry`, `GeocoderRegistry`, or system
  config. No geocoding support (input must already have `lat`/`lng`).

### Usage

```bash
php bin/console comivoyager:optimize <input> [options]
```

| Argument/Option | Description | Default |
|---|---|---|
| `input` (argument) | Path to a JSON file, or `-` for stdin | required |
| `-m, --method` | `haversine` or `vincenty` | `haversine` |
| `-r, --routes` | Number of top routes to return | `3` |
| `--return-to-start` | Treat as closed loop | off |
| `--depot` | 0-based index of the fixed start address | none (free start) |

### Input format

A JSON array of objects, each with `lat`/`lng` (required) and optional
`label`:

```json
[
  {"label": "London", "lat": 51.5074, "lng": -0.1278},
  {"label": "Paris", "lat": 48.8566, "lng": 2.3522},
  {"label": "Berlin", "lat": 52.5200, "lng": 13.4050}
]
```

### Example

```bash
echo '[
  {"label":"London","lat":51.5074,"lng":-0.1278},
  {"label":"Paris","lat":48.8566,"lng":2.3522},
  {"label":"Berlin","lat":52.5200,"lng":13.4050}
]' | php bin/console comivoyager:optimize - --method=vincenty --routes=2 --return-to-start
```

Output: the same `RouteCollection::toArray()` JSON shape as the HTTP API
(see above), pretty-printed.

### Exit codes

- `0` (`Command::SUCCESS`) — routes printed.
- `1` (`Command::FAILURE`) — unreadable input file, invalid JSON, missing
  `lat`/`lng` on an address, unknown `--method`, or fewer than 2 addresses
  (`InsufficientAddressesException`). Error message printed via
  `SymfonyStyle::error()`.

---

## VRP — Multi-Vehicle Routing

The VRP endpoint splits delivery orders across multiple drivers, each getting
a geographically tight, optimally-sequenced route within their capacity, shift,
and distance budget. See
[VRP_PROBLEM_DEFINITION.md](VRP_PROBLEM_DEFINITION.md) for the problem analysis.

### `POST /comivoyager/vrp/optimize`

- **File**: `Controller/VRPController.php`
- **Routing**: `genaker_comivoyager_vrp_optimize` (attribute route, `expose: true`)

#### Request

```json
{
  "depot": {"lat": 40.7128, "lng": -74.0060},
  "max_radius_miles": 100,
  "solver": "local",
  "vehicles": {
    "count": 3,
    "capacity_lbs": 40000,
    "max_stops": 12,
    "max_distance_miles": 200,
    "max_work_hours": 8,
    "avg_speed_mph": 35,
    "service_time_minutes": 15,
    "return_to_start": true,
    "start": {"lat": 40.71, "lng": -74.01},
    "end":   {"lat": 40.71, "lng": -74.01}
  },
  "orders": [
    {"id": "ORD-1", "lat": 40.75, "lng": -73.99, "weight_lbs": 8000, "priority": "normal", "customer_id": "C-100"}
  ]
}
```

| Field | Required | Description |
|---|---|---|
| `depot.lat` / `depot.lng` | yes | Shared warehouse / fallback start point |
| `orders[]` | yes | Each needs `lat`, `lng`; optional `id`, `weight_lbs`, `priority` (`normal`\|`high`\|`urgent`), `customer_id` |
| `max_radius_miles` | no | Orders beyond this are flagged `out_of_range` (default `100`) |
| `solver` | no | `"local"` (K-Means + TSP, free) or `"google"` (Cloud Fleet Routing, paid). Defaults to System Configuration value. |
| `vehicles.count` | no | Number of identical drivers (default `1`) |
| `vehicles.*` | no | Shared per-driver settings — see [Per-driver parameters](#per-driver-parameters) below |

#### Heterogeneous fleet

Instead of `vehicles` (homogeneous), pass a `drivers` array where each driver
has their own settings:

```json
{
  "depot": {"lat": 40.7128, "lng": -74.0060},
  "drivers": [
    {"id": 1, "capacity_lbs": 40000, "start": {"lat": 40.9, "lng": -73.9}, "max_work_hours": 6},
    {"id": 2, "capacity_lbs": 26000, "return_to_start": false, "avg_speed_mph": 25}
  ],
  "orders": [ ... ]
}
```

#### Per-driver parameters

| Field | Default | Description |
|---|---|---|
| `capacity_lbs` | `0` (unlimited) | Max load weight |
| `max_stops` | `0` (unlimited) | Max deliveries per shift |
| `max_distance_miles` | `0` (unlimited) | Driving-distance cap per shift |
| `start` | depot | Driver's home / start `{lat, lng}` |
| `end` | — | Explicit end `{lat, lng}` |
| `return_to_start` | `true` | Round trip back to start |
| `avg_speed_mph` | `30` | Driving speed (miles → hours) |
| `service_time_minutes` | `10` | Unload time per stop |
| `max_work_hours` | `0` (unlimited) | Shift length; route trimmed to fit |

#### Response

```json
{
  "routes": [
    {
      "vehicle": 1,
      "stops": ["ORD-1", "ORD-2", "ORD-5"],
      "stop_count": 3,
      "total_distance_miles": 13.4,
      "total_weight_lbs": 35000,
      "total_duration_hours": 0.9,
      "return_leg_miles": 4.1,
      "stop_details": [
        {
          "sequence": 1, "order_id": "ORD-1",
          "lat": 40.75, "lng": -73.99, "weight_lbs": 8000,
          "leg_distance_miles": 2.7, "cumulative_distance_miles": 2.7,
          "arrival_hours": 0.077, "departure_hours": 0.327, "eta_minutes": 4.6
        }
      ]
    }
  ],
  "summary": {
    "total_distance_miles": 33.5,
    "max_route_distance": 20.1,
    "max_route_duration_hours": 1.1,
    "vehicles_used": 2,
    "orders_assigned": 6,
    "orders_unassigned": 0,
    "orders_out_of_range": 0
  },
  "solver": "local"
}
```

- `unassigned` orders: dropped because no driver had capacity, or because they
  exceeded a driver's shift-hours / distance budget.
- `out_of_range` orders: beyond `max_radius_miles` from the depot.

**`stop_details`** — per-stop leg distance + ETA, in visiting order:

| Field | Description |
|---|---|
| `sequence` | 1-based position in the route |
| `leg_distance_miles` | distance from the previous point (start, or prior stop) |
| `cumulative_distance_miles` | running distance from the start |
| `arrival_hours` / `departure_hours` | hours into the shift (0 = leaving start); departure = arrival + service time |
| `eta_minutes` | `arrival_hours × 60` |

`return_leg_miles` is the final leg back to the end point (0 for an open route).

### `POST /comivoyager/vrp/optimize-orders`

Same solver, but the orders are pulled from **OroCommerce** instead of the
request body — filtered by internal status and geocoded automatically.

- **File**: `Controller/VRPController.php::optimizeOrdersAction`
- **Order source**: `Provider/DeliveryOrderProviderInterface` → `OroOrderProvider`

```json
{
  "depot": {"lat": 40.7128, "lng": -74.0060},
  "statuses": ["order_internal_status.open"],
  "limit": 200,
  "created_after": "2026-06-23T00:00:00+00:00",
  "max_radius_miles": 100,
  "vehicles": {"count": 3, "capacity_lbs": 40000, "max_work_hours": 8}
}
```

| Field | Required | Description |
|---|---|---|
| `depot` | yes | Shared warehouse / fallback start |
| `vehicles` / `drivers` | yes | Same fleet config as `/vrp/optimize` |
| `statuses` | no | Internal-status codes (full enum id or short id). Empty = any. |
| `limit` | no | Max orders to fetch (default `500`) |
| `created_after` | no | ISO-8601 timestamp; only orders created on/after |
| `max_radius_miles` | no | Delivery radius (default `100`) |

Response is identical to `/vrp/optimize`. Orders without a geocodable shipping
address are skipped. Order **weight** comes from the project's
`OrderWeightResolverInterface` (default `NullWeightResolver` → `0` lbs).

### CLI: `comivoyager:vrp:optimize`

```bash
php bin/console comivoyager:vrp:optimize <json-file> [options]
```

| Option | Default | Description |
|---|---|---|
| `--vehicles=`, `-k` | `2` | Number of drivers |
| `--capacity=`, `-c` | `0` | Capacity per driver (lbs) |
| `--max-stops=` | `0` | Max deliveries per driver |
| `--work-hours=` | `0` | Max shift hours per driver |
| `--speed=` | `30` | Average driving speed (mph) |
| `--service-time=` | `10` | Service time per stop (minutes) |
| `--one-way` | off | Open routes — do not return to start |
| `--radius=`, `-r` | `100` | Max delivery radius (miles) |

```bash
php bin/console comivoyager:vrp:optimize orders.json \
    --vehicles=3 --capacity=40000 --work-hours=8 --speed=35 --max-stops=12
```

Output: one section per driver (ordered stops, distance, weight, shift hours),
warnings for `unassigned` / `out_of_range` orders, and a totals summary.
