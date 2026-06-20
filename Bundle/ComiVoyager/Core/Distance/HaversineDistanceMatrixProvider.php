<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Distance;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;

/**
 * Great-circle distance on a sphere of mean Earth radius. Pure PHP, no
 * external dependencies — the default distance method.
 *
 * Full write-up (formula derivation, worked examples, accuracy analysis,
 * when to use): {@see ../../doc/distance-algorithms/HAVERSINE.md}.
 * Setup: none required, see {@see ../../doc/INSTALLATION.md}.
 */
final class HaversineDistanceMatrixProvider implements DistanceMatrixProviderInterface
{
    /**
     * Mean radius of the Earth in kilometres, used to convert the central
     * angle between two points into a surface distance. 6371.0 km is the
     * commonly used "spherical Earth" approximation (real Earth is an
     * oblate spheroid — see VincentyDistanceMatrixProvider for the
     * ellipsoid-aware alternative).
     */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Builds the full N x N matrix of great-circle distances (in km)
     * between every pair of input coordinates.
     *
     * Every cell is computed independently (including both (i,j) and
     * (j,i), which are mathematically equal) because each cell is just a
     * handful of trig calls — recomputing is cheaper than the bookkeeping
     * needed to exploit symmetry. Compare with PostgisDistanceMatrixProvider,
     * which *does* exploit symmetry because each of its cells costs a
     * network round trip.
     *
     * @param Coordinate[] $coordinates
     */
    public function build(array $coordinates): DistanceMatrix
    {
        $size = count($coordinates);
        $matrix = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                // The diagonal (a point's distance to itself) is always
                // zero and is hardcoded rather than computed, avoiding a
                // pointless atan2(0, 1) call for every stop.
                $matrix[$i][$j] = $i === $j ? 0.0 : $this->distance($coordinates[$i], $coordinates[$j]);
            }
        }

        return new DistanceMatrix($matrix);
    }

    /**
     * Identifier used in configuration (`genaker_comi_voyager.distance_provider`)
     * and the `method` field of the HTTP API to select this provider.
     */
    public function getName(): string
    {
        return 'haversine';
    }

    /**
     * Computes the great-circle distance between two points using the
     * haversine formula:
     *
     *   a = sin²(Δlat / 2) + cos(lat1) · cos(lat2) · sin²(Δlng / 2)
     *   c = 2 · atan2(√a, √(1 − a))
     *   d = R · c
     *
     * `a` is the square of half the chord length between the two points
     * (expressed via the haversine of the central angle); `c` is the
     * resulting central angle in radians; multiplying by the Earth's
     * radius `R` converts that angle into an arc length (km).
     */
    private function distance(Coordinate $from, Coordinate $to): float
    {
        // Convert both latitudes to radians — required by PHP's trig
        // functions (sin/cos/atan2 all operate in radians, not degrees).
        $lat1 = deg2rad($from->lat);
        $lat2 = deg2rad($to->lat);

        // Differences in latitude/longitude, also in radians.
        $deltaLat = deg2rad($to->lat - $from->lat);
        $deltaLng = deg2rad($to->lng - $from->lng);

        // Haversine of the central angle between the two points.
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        // atan2(sqrt(a), sqrt(1-a)) is mathematically equivalent to
        // 2*asin(sqrt(a)), but numerically stable even when `a` approaches
        // 1 (i.e. for near-antipodal points), where asin's derivative
        // blows up and small floating-point errors get amplified.
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        // Arc length = radius * central angle (in radians).
        return self::EARTH_RADIUS_KM * $c;
    }
}
