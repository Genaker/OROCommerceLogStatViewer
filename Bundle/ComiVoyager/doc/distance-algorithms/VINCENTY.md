# Vincenty Distance Provider

| | |
|---|---|
| **Name** (`method` / `getName()`) | `vincenty` |
| **File** | [`Core/Distance/VincentyDistanceMatrixProvider.php`](../../Core/Distance/VincentyDistanceMatrixProvider.php) |
| **Namespace** | `Genaker\Bundle\ComiVoyager\Core\Distance` |
| **Layer** | `Core/` — pure PHP, zero Symfony/Oro dependencies |
| **Default?** | No (opt-in via `genaker_comi_voyager.distance_provider` or per-request `method`) |
| **External calls** | None |
| **Unit tests** | [`Core/Tests/Unit/Distance/VincentyDistanceMatrixProviderTest.php`](../../Core/Tests/Unit/Distance/VincentyDistanceMatrixProviderTest.php) |
| **Installation** | None — pure PHP, works out of the box. See [../INSTALLATION.md](../INSTALLATION.md) for general bundle setup. |

---

## 1. What it computes

The **geodesic distance** between two points on the **WGS-84 reference
ellipsoid** — the same ellipsoid model GPS satellites use. Unlike
[`haversine`](HAVERSINE.md), which models Earth as a perfect sphere,
Vincenty's formula models Earth's actual **oblate spheroid** shape (slightly
flattened at the poles, bulging at the equator).

It still does **not** account for roads, terrain, or obstacles — it is a
**straight-line** (geodesic) distance, just a more geometrically accurate one
than haversine.

## 2. WGS-84 ellipsoid parameters

```php
private const SEMI_MAJOR_AXIS_M = 6378137.0;           // 'a': equatorial radius
private const FLATTENING = 1 / 298.257223563;          // 'f': ellipsoid flattening
private const MAX_ITERATIONS = 200;
private const CONVERGENCE_THRESHOLD = 1e-12;           // radians
```

- **`a` (semi-major axis)** = 6,378,137.0 m — the equatorial radius.
- **`f` (flattening)** = 1/298.257223563 ≈ 0.0033528 — how much the
  ellipsoid is "squashed" at the poles relative to the equator.
- **`b` (semi-minor axis)** = `(1 − f) · a` ≈ 6,356,752.314 m — the polar
  radius, derived from `a` and `f`.

These three constants fully define the WGS-84 ellipsoid — the same datum
used by GPS, Google Maps, and OpenStreetMap, so coordinates from those
sources are directly compatible with this calculation.

## 3. The algorithm (Vincenty's inverse formula, 1975)

Vincenty's *inverse* problem: given two points (lat/lng), find the geodesic
distance and azimuths between them. (The *direct* problem — given a point,
bearing, and distance, find the destination — is not implemented here, only
the inverse is needed.)

### High-level steps

1. Compute **reduced latitudes** `U1`, `U2` (latitude on an auxiliary sphere
   that has the same flattening-adjusted relationship to the ellipsoid):
   ```
   U1 = atan((1 − f) · tan(lat1))
   U2 = atan((1 − f) · tan(lat2))
   ```
2. Initialize `λ = L` (the difference in longitude, `Δlng`).
3. **Iterate** (up to `MAX_ITERATIONS = 200` times):
   - Compute `sin σ`, `cos σ`, `σ` (angular separation on the auxiliary
     sphere) from `U1`, `U2`, `λ`.
   - Compute `α` (azimuth of the geodesic at the equator) and `cos²α`.
   - Compute `cos(2σm)` (angular distance from the equator to the midpoint
     of the line).
   - Compute correction term `C`.
   - Update `λ` using the corrected formula.
   - **Converge** when `|λ_new − λ_old| < 1e-12` radians, or give up after
     200 iterations (the loop exits either way via the `do...while`
     condition — see §5 for what happens on non-convergence).
4. Once converged, compute `u²`, `A`, `B` (series-expansion correction
   coefficients) and `Δσ` (a higher-order correction to `σ`).
5. Final distance:
   ```
   distance_m = b · A · (σ − Δσ)
   ```
6. Convert to km: `distance_m / 1000.0`.

### Why iteration is needed (vs. haversine's closed form)

On a sphere, the geodesic between two points is a simple great-circle arc
with a closed-form formula. On an **ellipsoid**, geodesics don't have a
simple closed form — the shortest path's geometry depends on where along the
path you are (curvature varies with latitude). Vincenty's method handles this
by iteratively refining an auxiliary-sphere approximation until it converges
to the ellipsoidal geodesic to within `1e-12` radians (~6 nanometers of
arc on Earth's surface — far beyond any practical precision need).

## 4. Code walkthrough

```php
do {
    $sinLambda = sin($lambda);
    $cosLambda = cos($lambda);

    $sinSigma = sqrt(
        ($cosU2 * $sinLambda) ** 2
        + ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda) ** 2
    );

    if ($sinSigma === 0.0) {
        return 0.0; // coincident points
    }

    $cosSigma = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLambda;
    $sigma = atan2($sinSigma, $cosSigma);

    $sinAlpha = $cosU1 * $cosU2 * $sinLambda / $sinSigma;
    $cosSqAlpha = 1 - $sinAlpha ** 2;

    $cos2SigmaM = $cosSqAlpha !== 0.0
        ? $cosSigma - 2 * $sinU1 * $sinU2 / $cosSqAlpha
        : 0.0; // equatorial line edge case

    $bigC = $f / 16 * $cosSqAlpha * (4 + $f * (4 - 3 * $cosSqAlpha));

    $lambdaPrevious = $lambda;
    $lambda = $bigL + (1 - $bigC) * $f * $sinAlpha
        * ($sigma + $bigC * $sinSigma * ($cos2SigmaM + $bigC * $cosSigma * (-1 + 2 * $cos2SigmaM ** 2)));
} while (abs($lambda - $lambdaPrevious) > self::CONVERGENCE_THRESHOLD && --$iterationsLeft > 0);
```

Two **edge-case guards** worth noting:

- `$sinSigma === 0.0` → the two points are **coincident** (or
  antipodal-on-the-auxiliary-sphere in a degenerate way) → returns `0.0`
  immediately, short-circuiting the rest of the algorithm.
- `$cosSqAlpha !== 0.0` guard around `$cos2SigmaM` → when `cosSqAlpha = 0`
  (the geodesic is exactly along the **equator**, `α = 90°`), the division
  `2·sinU1·sinU2 / cosSqAlpha` would be `0/0`. The code special-cases this to
  `cos2SigmaM = 0.0`, which is the correct value for an equatorial geodesic.

After the loop, the final correction terms:

```php
$uSq = $cosSqAlpha * ($a ** 2 - $b ** 2) / $b ** 2;
$bigA = 1 + $uSq / 16384 * (4096 + $uSq * (-768 + $uSq * (320 - 175 * $uSq)));
$bigB = $uSq / 1024 * (256 + $uSq * (-128 + $uSq * (74 - 47 * $uSq)));

$deltaSigma = $bigB * $sinSigma * ($cos2SigmaM + $bigB / 4 * (
    $cosSigma * (-1 + 2 * $cos2SigmaM ** 2)
    - $bigB / 6 * $cos2SigmaM * (-3 + 4 * $sinSigma ** 2) * (-3 + 4 * $cos2SigmaM ** 2)
));

$distanceM = $b * $bigA * ($sigma - $deltaSigma);
```

`A` and `B` are truncated series expansions (in powers of `u²`) that
approximate elliptic integrals — this is what makes Vincenty's formula
accurate to sub-millimeter precision without needing actual elliptic
integral evaluation.

## 5. Worked examples

### Classic test case: Flinders Peak → Buninyong

This is the **canonical reference test case** from Vincenty's original 1975
paper, used to validate implementations:

```php
$matrix = (new VincentyDistanceMatrixProvider())->build([
    new Coordinate(-37.95103341667, 144.42486789),    // Flinders Peak
    new Coordinate(-37.65282113889, 143.92649552778), // Buninyong
]);

$matrix->distanceBetween(0, 1); // ≈ 54.972271 km
```

Known reference value: **54,972.271 m** with ellipsoidal azimuths
`306°52'05.37"` and `86°25'41.62"` (azimuths are not computed/returned by
this implementation, only distance). The unit test asserts the result
matches to within 1 meter (`0.001 km` delta).

### London → Paris

```php
$matrix = (new VincentyDistanceMatrixProvider())->build([
    new Coordinate(51.5074, -0.1278), // London
    new Coordinate(48.8566, 2.3522),  // Paris
]);

$matrix->distanceBetween(0, 1); // ≈ 343.6 km
```

Compare to haversine's **343.5 km** for the same pair (see
[HAVERSINE.md](HAVERSINE.md)) — a difference of about **0.1 km (0.03%)**.
This is typical: the sphere-vs-ellipsoid correction is small for short/medium
distances, and **dwarfed by the straight-line-vs-road gap** (London–Paris
road distance is ~620 km via OSRM).

## 6. Complexity & performance

| | |
|---|---|
| **Time per pair** | O(1), but with a `do...while` loop of up to 200 iterations (typically converges in **3-5** iterations for non-pathological inputs) |
| **Time for N points** | O(N²) — full matrix (same as haversine, no symmetry shortcut) |
| **Space** | O(N²) |
| **External I/O** | None |
| **Relative cost vs. haversine** | ~5-10x more CPU per pair (multiple trig calls per iteration × several iterations vs. haversine's single-pass trig) — still sub-millisecond per pair, negligible in absolute terms for typical N |

## 7. Convergence and known failure modes

- **`MAX_ITERATIONS = 200`**: the loop stops after 200 iterations even if
  `|λ_new − λ_old| > 1e-12` (via `--$iterationsLeft > 0` becoming false).
  When this happens, the **last computed value** of `σ`, `cos2SigmaM`, etc.
  is used to compute a result anyway — i.e. **no exception is thrown on
  non-convergence**, the function returns its best approximation so far.
- **Nearly-antipodal points**: Vincenty's original 1975 inverse formula is
  documented to **converge slowly or fail to converge** for points that are
  nearly antipodal (on opposite sides of the Earth, e.g. separated by close
  to 180° of longitude on the equator). This is a well-known limitation of
  the *inverse* method (later addressed by Karney's 2013 algorithm, which is
  **not** what's implemented here). For near-antipodal pairs, this provider
  may return a less accurate result without any error indication.
  - **Practical impact**: route-optimization use cases involve delivery
    addresses, which are essentially never antipodal to each other (that
    would mean, e.g., one stop in London and another in the middle of the
    Pacific south of New Zealand). This edge case is theoretical for typical
    bundle usage but worth knowing if coordinates could ever be
    programmatically generated/randomized (e.g. in fuzz tests).
- **Coincident points**: handled explicitly (`$sinSigma === 0.0` → returns
  `0.0`), no risk of division-by-zero there.
- **Equatorial geodesics**: handled explicitly (`cosSqAlpha !== 0.0` guard),
  no division-by-zero.

## 8. Accuracy analysis

| Comparison | Typical magnitude |
|---|---|
| Vincenty vs. true ellipsoidal geodesic | Sub-millimeter (for converging cases) |
| Vincenty vs. haversine | ~0.1–0.5%, varies with latitude/bearing |
| Vincenty (or haversine) vs. real road distance | **20–80%+ underestimate**, dominates all other error sources |

**The headline takeaway**: Vincenty's extra accuracy over haversine
(fractions of a percent) is **far smaller** than the gap between any
straight-line method and real road distance (tens of percent). Choosing
Vincenty over haversine **does not** make routes "more realistic" in any way
a user would notice — it only matters if you need geodetically precise
*distance values* for reporting/auditing purposes (e.g. regulatory mileage
calculations that specifically require WGS-84 geodesic distance).

## 9. When to use

✅ **Use `vincenty` when:**
- You need **geodetically accurate straight-line distances** as reported
  values (not just for ranking) — e.g. for compliance/reporting that
  specifies WGS-84 geodesic distance.
- You're already paying the O(N²) PHP cost and the ~5-10x multiplier over
  haversine is acceptable (still negligible for typical N).

❌ **Avoid `vincenty` when:**
- You just need route *ranking* — haversine gives essentially the same
  ranking at a fraction of the CPU cost.
- You need road-aware distances — neither `vincenty` nor `haversine`
  addresses this; use [OSRM.md](OSRM.md) or [GOOGLE.md](GOOGLE.md).
- Coordinates could plausibly be near-antipodal (extremely unlikely for
  delivery addresses, but worth knowing for synthetic/test data).

## 10. Related

- [HAVERSINE.md](HAVERSINE.md) — simpler, faster, same straight-line
  limitation, ~0.1-0.5% less accurate.
- [OSRM.md](OSRM.md) / [GOOGLE.md](GOOGLE.md) — road-aware alternatives that
  address the much larger straight-line-vs-road gap.
- [../ALGORITHMS.md](../ALGORITHMS.md) — how the resulting `DistanceMatrix`
  is consumed by the TSP solver.
