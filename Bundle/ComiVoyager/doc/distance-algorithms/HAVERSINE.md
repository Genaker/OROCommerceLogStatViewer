# Haversine Distance Provider

| | |
|---|---|
| **Name** (`method` / `getName()`) | `haversine` |
| **File** | [`Core/Distance/HaversineDistanceMatrixProvider.php`](../../Core/Distance/HaversineDistanceMatrixProvider.php) |
| **Namespace** | `Genaker\Bundle\ComiVoyager\Core\Distance` |
| **Layer** | `Core/` — pure PHP, zero Symfony/Oro dependencies |
| **Default?** | **Yes** — `genaker_comi_voyager.distance_provider` defaults to `haversine` |
| **External calls** | None |
| **Unit tests** | [`Core/Tests/Unit/Distance/HaversineDistanceMatrixProviderTest.php`](../../Core/Tests/Unit/Distance/HaversineDistanceMatrixProviderTest.php) |
| **Installation** | None — pure PHP, works out of the box. See [../INSTALLATION.md](../INSTALLATION.md) for general bundle setup. |

---

## 1. What it computes

The **great-circle distance** between two points on the surface of a sphere
— the shortest path between them along the sphere's surface (not through its
interior). This is the classic "as the crow flies" distance, assuming Earth
is a perfect sphere.

It does **not** account for:
- Roads, terrain, or obstacles (rivers, mountains, buildings).
- Earth's actual shape (an oblate spheroid, not a perfect sphere — see
  [VINCENTY.md](VINCENTY.md) for the ellipsoidal correction).

## 2. The formula

Given two points `(lat1, lng1)` and `(lat2, lng2)` in degrees, converted to
radians:

```
Δlat = lat2 - lat1
Δlng = lng2 - lng1

a = sin²(Δlat / 2) + cos(lat1) · cos(lat2) · sin²(Δlng / 2)
c = 2 · atan2(√a, √(1 − a))
d = R · c
```

where `R` is the mean radius of the Earth, taken as **6371.0 km**
(`EARTH_RADIUS_KM` constant in the source).

### Why `atan2(√a, √(1−a))` instead of `asin(√a)`

A more commonly seen form is `c = 2 · asin(√a)`. Both are mathematically
equivalent for `a ∈ [0, 1]`, but `atan2` is **numerically more stable** near
`a ≈ 1` (antipodal points), where `asin`'s derivative blows up and small
floating-point errors get amplified. `atan2(y, x)` has no such singularity
for `x, y ≥ 0`. This implementation uses the more robust `atan2` form.

### Derivation sketch

The formula comes from the **spherical law of haversines**. The
"haversine" of an angle θ is defined as:

```
haversine(θ) = sin²(θ/2) = (1 − cos θ) / 2
```

For two points on a sphere separated by central angle `c`, the spherical law
of cosines gives `cos c = sin(lat1)·sin(lat2) + cos(lat1)·cos(lat2)·cos(Δlng)`.
Substituting the haversine identity and simplifying yields the `a`/`c`
formula above. The haversine form is preferred over the law-of-cosines form
because it remains numerically accurate for **small angles** (nearby
points), where `cos c ≈ 1` and `acos` loses precision — exactly the regime
most logistics use cases operate in.

## 3. Code walkthrough

```php
final class HaversineDistanceMatrixProvider implements DistanceMatrixProviderInterface
{
    private const EARTH_RADIUS_KM = 6371.0;

    public function build(array $coordinates): DistanceMatrix
    {
        $size = count($coordinates);
        $matrix = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $matrix[$i][$j] = $i === $j ? 0.0 : $this->distance($coordinates[$i], $coordinates[$j]);
            }
        }

        return new DistanceMatrix($matrix);
    }
    // ...
}
```

- **Full N×N matrix is computed**, not just the upper triangle — even though
  `distance($a, $b) === distance($b, $a)` mathematically. This costs 2x the
  CPU of a triangle-only computation, but for haversine that cost is
  negligible (see §5), and it keeps the implementation simple/branch-free.
  Compare with [`PostgisDistanceMatrixProvider`](POSTGIS.md), which *does*
  exploit symmetry because each cell costs a DB round trip.
- The diagonal (`$i === $j`) is hardcoded to `0.0` rather than computed —
  `distance(p, p)` would mathematically yield `0.0` anyway (`a = 0`,
  `c = 0`), but skipping it avoids `atan2(0, 1)` calls for no benefit.

## 4. Worked example: London → Paris

```php
$matrix = (new HaversineDistanceMatrixProvider())->build([
    new Coordinate(51.5074, -0.1278), // London
    new Coordinate(48.8566, 2.3522),  // Paris
]);

$matrix->distanceBetween(0, 1); // ≈ 343.5 km
```

Step by step:
- `lat1 = 51.5074°`, `lat2 = 48.8566°` → `Δlat = -2.6508°` → `-0.046267 rad`
- `lng1 = -0.1278°`, `lng2 = 2.3522°` → `Δlng = 2.4800°` → `0.043284 rad`
- `a = sin²(-0.023134) + cos(0.89884)·cos(0.85257)·sin²(0.021642)`
- `a ≈ 0.0002683 + 0.6224 · 0.6572 · 0.0002342 ≈ 0.0003633`
- `c = 2·atan2(√0.0003633, √0.9996367) ≈ 0.038139 rad`
- `d = 6371.0 · 0.038139 ≈ 343.5 km`

Compare:
- **Real driving distance** London → Paris (via Eurotunnel/M20): ~460 km.
- **OSRM road distance** (per [DISTANCE_PROVIDERS.md](../DISTANCE_PROVIDERS.md)
  smoke test): ~620 km (via the routed road network, which for this pair
  goes around water — see §6 for why this matters).
- **Haversine straight-line**: ~343.5 km — about **45–80% lower** than road
  distance, illustrating the underestimate magnitude for a route that
  crosses a sea.

## 5. Complexity & performance

| | |
|---|---|
| **Time per pair** | O(1) — fixed number of trig calls (`sin`, `cos`, `atan2`, `sqrt`) |
| **Time for N points** | O(N²) — full matrix, no shortcuts |
| **Space** | O(N²) — the matrix itself |
| **External I/O** | None |
| **Typical wall time** | Sub-millisecond for N up to several hundred |

Because every cell is a handful of floating-point trig operations with no
I/O, this provider is effectively "free" compared to any of the other four —
it will never be the bottleneck in `RouteOptimizationService::optimize()`.
The bottleneck for large N is always the **solver** (see
[ALGORITHMS.md](../ALGORITHMS.md)), not the distance provider.

## 6. Accuracy analysis

Haversine assumes Earth is a **perfect sphere of radius 6371.0 km** (the
mean radius). Reality:

- Earth is an **oblate spheroid**: equatorial radius ≈ 6378.137 km, polar
  radius ≈ 6356.752 km — a ~0.34% difference. This introduces a
  **systematic error of roughly ±0.5%** depending on latitude and bearing,
  which [VINCENTY.md](VINCENTY.md) corrects for.
- **Far larger than the ellipsoid error** is the **straight-line vs. road**
  gap. A straight line:
  - May cross water, mountains, borders, or private property — physically
    undrivable.
  - Ignores road curvature, one-way restrictions, and detours around
    obstacles.
  - The ratio of road distance to straight-line distance (the
    "**circuity factor**") typically ranges from **1.2–1.6x** for normal
    terrain, but can be **2x–10x+** for coastal, riverine, or mountainous
    regions, or when a body of water sits directly between two points (as
    with London–Paris above).

**Practical implication**: haversine is reliable for *relative ranking*
(which of several candidate routes is shortest) only when all candidate
routes have **similar circuity factors** — e.g. all stops are in the same
flat urban area. When stops span very different terrains (some pairs
require ferries/bridges, others don't), haversine's ranking can diverge
meaningfully from the true road-distance ranking. In those cases, prefer
[`osrm`](OSRM.md) or [`google`](GOOGLE.md).

## 7. Edge cases

- **Same point twice** (`a === b`): returns `0.0` exactly (handled by the
  diagonal shortcut for `i === j`; for *distinct indices* holding the same
  coordinate, `a = 0` → `c = 0` → `d = 0.0`).
- **Antipodal points** (e.g. `(0, 0)` and `(0, 180)`): `a → 1`, `c → π`,
  `d → πR ≈ 20015.1 km` (half the Earth's circumference). The `atan2` form
  handles this correctly without the precision loss `2·asin(√a)` would have
  near `a = 1`.
- **Poles**: `cos(±90°) = 0`, so the `cos(lat1)·cos(lat2)·sin²(Δlng/2)` term
  vanishes — longitude becomes irrelevant at the poles, which is physically
  correct (all longitudes meet at a pole).
- **Coordinate validation**: `Coordinate`'s constructor
  (`Core/Model/Coordinate.php`) throws `\InvalidArgumentException` for
  `lat ∉ [-90, 90]` or `lng ∉ [-180, 180]` *before* this provider ever sees
  the value — `HaversineDistanceMatrixProvider` itself does no validation.

## 8. When to use

✅ **Use `haversine` when:**
- You want the **default, zero-config, zero-cost** option.
- N is large and you can't afford O(N) external calls.
- Stops are in a roughly uniform-terrain area (no major water/mountain
  crossings between candidate stops).
- You need a fast **fallback** when `osrm`/`google`/`postgis` are
  unavailable.

❌ **Avoid `haversine` when:**
- Reported distances will be shown to users as "driving distance" — they
  will look wrong (often 30-80% too low).
- Some candidate pairs cross water/mountains and others don't — ranking can
  be misleading (use [`osrm`](OSRM.md) or [`google`](GOOGLE.md)).
- ETA/duration is needed (haversine has no concept of speed).

## 9. Related

- [VINCENTY.md](VINCENTY.md) — same straight-line concept, ellipsoidal
  correction, ~10x more CPU.
- [OSRM.md](OSRM.md) / [GOOGLE.md](GOOGLE.md) — road-aware alternatives.
- [POSTGIS.md](POSTGIS.md) — same great-circle math, computed in SQL instead
  of PHP.
- [../ALGORITHMS.md](../ALGORITHMS.md) — how the resulting `DistanceMatrix`
  is consumed by the TSP solver.
