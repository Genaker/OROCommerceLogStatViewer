# PostGIS Distance Provider

| | |
|---|---|
| **Name** (`method` / `getName()`) | `postgis` |
| **File** | [`Distance/PostgisDistanceMatrixProvider.php`](../../Distance/PostgisDistanceMatrixProvider.php) |
| **Namespace** | `Genaker\Bundle\ComiVoyager\Distance` |
| **Layer** | Bundle root — Symfony/Oro-aware (Doctrine DBAL `Connection`, `LoggerInterface`) |
| **Default?** | No (opt-in via `genaker_comi_voyager.distance_provider` or per-request `method`) |
| **External calls** | SQL queries against a **dedicated** `comivoyager_postgis` DBAL connection |
| **Config** | `ORO_COMIVOYAGER_POSTGIS_DSN` env var (default provided by the bundle itself) |
| **Unit tests** | [`Tests/Unit/Distance/PostgisDistanceMatrixProviderTest.php`](../../Tests/Unit/Distance/PostgisDistanceMatrixProviderTest.php) (mocked `Connection`); also covered indirectly via `DistanceProviderRegistryTest` |
| **Installation** | See [../INSTALLATION.md §5](../INSTALLATION.md#5-optional-postgis-distance-provider-separate-database) — Docker Compose service ([§5.2](../INSTALLATION.md#52-docker-compose-service-infrastructure--must-be-added-manually)) or [§5.7 without Docker](../INSTALLATION.md#57-running-postgis-without-docker). |

---

## 1. What it computes

The same **great-circle distance** as [`haversine`](HAVERSINE.md) (sphere of
radius ~6371 km), but evaluated by **PostgreSQL/PostGIS** via the
`ST_DistanceSphere` function, over a **separate, non-default Postgres
connection** dedicated to this bundle.

This is **not** a road-distance provider — it has the same straight-line
limitations as haversine (see [HAVERSINE.md §6](HAVERSINE.md#6-accuracy-analysis)).
Its purpose is **architectural**: compute distance where the geospatial data
already lives, using a standard, well-tested PostGIS function, rather than
pulling coordinates into PHP.

## 2. The dedicated connection

Unlike the other four providers, `postgis` depends on Doctrine DBAL
infrastructure registered by the bundle itself
(`DependencyInjection/GenakerComiVoyagerExtension.php`):

```php
public function prepend(ContainerBuilder $container): void
{
    // ...
    $container->prependExtensionConfig('doctrine', [
        'dbal' => [
            'connections' => [
                'comivoyager_postgis' => [
                    'url' => '%env(ORO_COMIVOYAGER_POSTGIS_DSN)%',
                    'server_version' => '17',
                ],
            ],
        ],
        // ... ORM mappings for GeocodeCache, unrelated to this connection
    ]);
}

public function load(array $configs, ContainerBuilder $container): void
{
    // ...
    if (!$container->hasParameter('env(ORO_COMIVOYAGER_POSTGIS_DSN)')) {
        $container->setParameter(
            'env(ORO_COMIVOYAGER_POSTGIS_DSN)',
            'postgresql://comivoyager:comivoyager@comivoyager_postgis:5432/comivoyager'
        );
    }
    // ...
}
```

This produces a Doctrine DBAL service named
**`doctrine.dbal.comivoyager_postgis_connection`** (the standard
`doctrine.dbal.{name}_connection` naming convention for a connection named
`comivoyager_postgis`), wired into `PostgisDistanceMatrixProvider` via
`Resources/config/services.yml`:

```yaml
Genaker\Bundle\ComiVoyager\Distance\PostgisDistanceMatrixProvider:
  arguments:
    $connection: '@doctrine.dbal.comivoyager_postgis_connection'
  tags: ['genaker_comivoyager.distance_provider']
```

### Why a *separate* connection (not the default Oro DB)?

- **Decoupling**: this provider's data (or just its computation) doesn't
  need to live in the main Oro application database — it can point at a
  standalone PostGIS instance with its own lifecycle, scaling, and access
  controls.
- **DBAL-only, no ORM**: the connection has **no entity manager** —
  `PostgisDistanceMatrixProvider` receives a raw `Doctrine\DBAL\Connection`
  and runs hand-written SQL (`fetchOne`), not Doctrine entities/repositories.
  This is appropriate because the queries are pure computation
  (`ST_DistanceSphere` on parameters), not CRUD against mapped entities.
- **Self-contained bundle**: the connection is registered entirely from
  within the bundle's `prepend()`/`load()` — no edits to
  `config/doctrine.yml` or `config/config.yml` are needed (see
  [../INSTALLATION.md §5.1](../INSTALLATION.md#51-self-contained-bundle-configuration)).

## 3. The query

```php
private function fetchPairwiseDistances(array $coordinates): array
{
    $rows = [];
    $params = [];

    foreach ($coordinates as $index => $coordinate) {
        $rows[] = sprintf('(%d, :lng%1$d, :lat%1$d)', $index);
        $params['lng' . $index] = $coordinate->lng;
        $params['lat' . $index] = $coordinate->lat;
    }

    $values = implode(', ', $rows);

    $sql = <<<SQL
        SELECT p1.idx AS i, p2.idx AS j,
               ST_DistanceSphere(ST_MakePoint(p1.lng, p1.lat), ST_MakePoint(p2.lng, p2.lat)) AS meters
        FROM (VALUES {$values}) AS p1(idx, lng, lat)
        CROSS JOIN (VALUES {$values}) AS p2(idx, lng, lat)
        WHERE p1.idx < p2.idx
        SQL;

    return $this->connection->fetchAllAssociative($sql, $params);
}
```

- **`ST_MakePoint(x, y)`** — PostGIS convention is `(longitude, latitude)`,
  i.e. `(x, y)` matching standard Cartesian/GeoJSON ordering — **note the
  `lng` parameter comes first**, matching OSRM's convention but the
  *opposite* of Google's `lat,lng` (see [OSRM.md](OSRM.md) /
  [GOOGLE.md](GOOGLE.md) for those conventions). Three different
  lng/lat orderings across three providers — always check the relevant
  provider's docs/source before modifying coordinate handling.
- **`ST_DistanceSphere(geomA, geomB)`** — returns the distance in **meters**
  between two geometries, computed on a **sphere** (not the WGS-84
  ellipsoid — PostGIS also has `ST_DistanceSpheroid` for that, not used
  here). This is the PostGIS equivalent of the haversine formula —
  same accuracy class as [`haversine`](HAVERSINE.md), just computed in SQL.
- **`(VALUES (0, :lng0, :lat0), (1, :lng1, :lat1), ...)`** — each input
  coordinate becomes one row of an inline `VALUES` table, tagged with its
  original index (`idx`). This table is **cross-joined with itself**
  (`p1`/`p2`), and `WHERE p1.idx < p2.idx` keeps only the upper triangle —
  exploiting `ST_DistanceSphere(A, B) === ST_DistanceSphere(B, A)` to
  evaluate each unordered pair exactly once, in **one query**.
- **Named parameters** (`:lng0`, `:lat0`, etc.) — passed via DBAL's
  parameter binding, not string interpolation, so there's no SQL injection
  risk even though the values originate from user-supplied coordinates. Each
  parameter is referenced twice in the SQL text (once in each `VALUES`
  list) but bound once — PDO's pgsql driver resolves repeated named
  parameters to the same bound value.
- Each result row's `meters` is divided by `METERS_PER_KM = 1000.0` to match
  the km convention used by `DistanceMatrix` and all other providers.

### No spatial index / stored geometry needed

Note that `ST_MakePoint` constructs geometries **on the fly from
parameters** — this query does **not** read from any table, and therefore
needs **no spatial index, no `geometry` column, and no bundle-owned schema**
in the PostGIS database. `ST_DistanceSphere` is a pure function over two
constructed points. This is why §6 of [INSTALLATION.md](../INSTALLATION.md#6-migrations)
notes the PostGIS database needs no migration.

## 4. The single-round-trip pattern

```php
public function build(array $coordinates): DistanceMatrix
{
    $size = count($coordinates);
    $matrix = array_fill(0, $size, array_fill(0, $size, 0.0));

    if ($size < 2) {
        return new DistanceMatrix($matrix);
    }

    try {
        foreach ($this->fetchPairwiseDistances($coordinates) as ['i' => $i, 'j' => $j, 'meters' => $meters]) {
            $distanceKm = ((float) $meters) / self::METERS_PER_KM;
            $matrix[$i][$j] = $distanceKm;
            $matrix[$j][$i] = $distanceKm;
        }
    } catch (DbalException $exception) {
        $this->logger->error('PostgisDistanceMatrixProvider error', ['error' => $exception->getMessage()]);

        throw new DistanceProviderUnavailableException(
            'PostGIS distance provider is unavailable: ' . $exception->getMessage(),
            0,
            $exception
        );
    }

    return new DistanceMatrix($matrix);
}
```

- **Only the upper triangle is fetched** (`p1.idx < p2.idx` in the SQL),
  then each row is **mirrored** into `$matrix[$j][$i]` — exploiting the fact
  that `ST_DistanceSphere(A, B) === ST_DistanceSphere(B, A)`. This halves the
  number of *rows returned* compared to a naive full-matrix query, while
  still costing only **one round trip** regardless of N.
- This is the **opposite trade-off** from
  [`HaversineDistanceMatrixProvider`](HAVERSINE.md#3-code-walkthrough), which
  computes the *full* matrix in PHP because each cell is "free" (in-process
  trig). Here, the database does the trig, but **the network round trip is
  the expensive part** — so the query is batched to pay that cost once,
  not `N²` or `N(N-1)/2` times.
- The diagonal (`$matrix[$i][$i]`) stays `0.0` from the `array_fill`
  initialization — never queried (`p1.idx < p2.idx` excludes `i === j`).
- For `$size < 2` there are no pairs to compute, so `build()` returns the
  all-zero matrix without touching the database at all.

### Query count by N

| N | Queries |
|---|---|
| 2 | 1 |
| 5 | 1 |
| 10 | 1 |
| 15 | 1 |
| 500 | 1 |

Every `build()` call costs **exactly one round trip**, regardless of N —
matching [`osrm`](OSRM.md) and [`google`](GOOGLE.md), which also return the
entire matrix in a single request.

## 5. Performance implications

| | |
|---|---|
| **Queries for N points** | 1 |
| **Cost per query** | 1 network round trip to Postgres + `O(N²)` `ST_DistanceSphere` evaluations inside Postgres (no I/O, no index lookup — pure math on constructed points) |
| **Dominant cost** | The single round trip's latency, plus Postgres-side CPU for the cross join — both negligible for the address counts this solver targets |
| **Scaling** | O(1) round trips; the cross join itself is `O(N²)` rows examined (`O(N²/2)` after the `p1.idx < p2.idx` filter), all evaluated in-process by Postgres — far cheaper than `N(N-1)/2` separate round trips even for a local connection |

### History: why this used to be N(N-1)/2 round trips

The original implementation issued one `ST_DistanceSphere` query per
unordered pair (`N(N-1)/2` round trips, exploiting symmetry to halve a naive
`N²` loop) — "simplest correct implementation for the small N this solver
targets" per the original plan. That was the **only provider** whose cost
scaled with network latency rather than computation, and its own docblock
flagged a single batched `VALUES`/`CROSS JOIN` query as the natural fix. This
has since been implemented (§3-4 above): the whole upper triangle is now
returned in one round trip.

## 6. Error handling & failure modes

```php
} catch (DbalException $exception) {
    $this->logger->error('PostgisDistanceMatrixProvider error', ['error' => $exception->getMessage()]);

    throw new DistanceProviderUnavailableException(
        'PostGIS distance provider is unavailable: ' . $exception->getMessage(),
        0,
        $exception
    );
}
```

- **Any** `Doctrine\DBAL\Exception` — connection refused (DB container not
  running), authentication failure (wrong DSN credentials), timeout,
  malformed query — is caught around the single `fetchAllAssociative()`
  call and aborts the whole `build()` call.
- Logged to the `comivoyager` Monolog channel, then surfaces as
  `DistanceProviderUnavailableException` → HTTP **422 Unprocessable Entity**
  in `RouteOptimizationController`.
- **No retry logic** — a single transient connection blip fails the entire
  optimization request. With only one round trip per request, this is now
  the same exposure as the other single-request providers
  ([`osrm`](OSRM.md), [`google`](GOOGLE.md)).

## 7. Accuracy analysis

`ST_DistanceSphere` uses the **same spherical-Earth model** as
[`haversine`](HAVERSINE.md) (PostGIS's default sphere radius is ~6371 km,
matching this bundle's `EARTH_RADIUS_KM` constant). Therefore:

- **Accuracy is identical to `haversine`** — same straight-line,
  perfect-sphere assumptions, same ~20-80% underestimate vs. real road
  distance (see [HAVERSINE.md §6](HAVERSINE.md#6-accuracy-analysis)).
- PostGIS also offers `ST_DistanceSpheroid` (WGS-84 ellipsoid, like
  [`vincenty`](VINCENTY.md)) and `ST_Distance` with `geography` types
  (which internally uses spheroid calculations) — **none of these are used
  here**. Switching to `ST_DistanceSpheroid` would bring `postgis` to
  Vincenty-level accuracy at the cost of one extra parameter
  (`'SPHEROID["WGS 84",6378137,298.257223563]'`) — a possible future
  enhancement, not currently implemented.

**Choosing `postgis` over `haversine` is never about accuracy** — it's
purely about *where* the computation happens (in the database vs. in PHP).

## 8. When to use

✅ **Use `postgis` when:**
- The application **already has a PostGIS-enabled database** with relevant
  geospatial data, and keeping distance computation co-located/consistent
  with other PostGIS-based features (reports, spatial queries) is valuable.
- You want a **self-hosted, no-API-key, no-rate-limit** option but don't
  want to run OSRM (which requires pre-processed map extracts).
- Since §3-4, the single batched query keeps this practical even for the
  upper end of `max_addresses` (default 9, raisable to 1000) without the round-trip cost
  scaling with N.

❌ **Avoid `postgis` when:**
- You don't already have a PostGIS database — `haversine` gives identical
  accuracy with **zero infrastructure** (no second DB connection to
  provision/monitor).
- You need road-aware distances — `postgis`'s `ST_DistanceSphere` is
  straight-line, same as haversine; use [`osrm`](OSRM.md) or
  [`google`](GOOGLE.md) for road distances.

## 9. Related

- [HAVERSINE.md](HAVERSINE.md) — identical accuracy, computed in PHP instead
  of SQL, no second DB connection needed.
- [VINCENTY.md](VINCENTY.md) — what `postgis` *could* match in accuracy via
  `ST_DistanceSpheroid` (not currently implemented).
- [../INSTALLATION.md §5](../INSTALLATION.md#5-optional-postgis-distance-provider-separate-database) —
  full setup (Docker Compose service, env var, bare-metal install).
- [../API.md](../API.md) — error response shapes (`422` on
  `DistanceProviderUnavailableException`).
