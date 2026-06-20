<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Distance;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;

/**
 * Geodesic distance on the WGS-84 ellipsoid via Vincenty's inverse formula.
 * More accurate than haversine, still pure PHP with no external dependencies.
 *
 * Full write-up (algorithm steps, worked examples incl. the Flinders
 * Peak -> Buninyong reference case, convergence/non-convergence behavior,
 * accuracy comparison): {@see ../../doc/distance-algorithms/VINCENTY.md}.
 * Setup: none required, see {@see ../../doc/INSTALLATION.md}.
 */
final class VincentyDistanceMatrixProvider implements DistanceMatrixProviderInterface
{
    /** WGS-84 semi-major axis 'a' (equatorial radius), in metres. */
    private const SEMI_MAJOR_AXIS_M = 6378137.0;

    /** WGS-84 flattening 'f' — how much the ellipsoid is squashed at the poles. */
    private const FLATTENING = 1 / 298.257223563;

    /**
     * Safety cap on the iterative refinement below. Vincenty's inverse
     * formula normally converges in 3-5 iterations; 200 is a generous
     * ceiling for slow-converging (near-antipodal) cases. If the cap is
     * hit, the loop simply stops and the last computed values are used —
     * no exception is thrown (see doc §7 for the known non-convergence
     * limitation).
     */
    private const MAX_ITERATIONS = 200;

    /**
     * Convergence threshold in radians for the iterative `lambda` update.
     * 1e-12 rad corresponds to roughly 6 nanometres of arc on Earth's
     * surface — far beyond any practical precision requirement.
     */
    private const CONVERGENCE_THRESHOLD = 1e-12;

    /**
     * Builds the full N x N matrix of WGS-84 geodesic distances (in km)
     * between every pair of input coordinates.
     *
     * Like HaversineDistanceMatrixProvider, the full matrix is computed
     * (no symmetry shortcut) — each cell is still pure CPU, just ~5-10x
     * more expensive than haversine due to the iterative refinement below.
     *
     * @param Coordinate[] $coordinates
     */
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

    /**
     * Identifier used in configuration (`genaker_comi_voyager.distance_provider`)
     * and the `method` field of the HTTP API to select this provider.
     */
    public function getName(): string
    {
        return 'vincenty';
    }

    /**
     * Vincenty's inverse formula (1975): given two points on the WGS-84
     * ellipsoid, returns the geodesic (shortest-path) distance between
     * them in kilometres.
     *
     * Unlike haversine's closed-form solution (valid for a perfect
     * sphere), there is no simple closed form for distances on an
     * ellipsoid because the curvature varies with latitude. Vincenty's
     * method works around this by iteratively refining an approximation
     * on an "auxiliary sphere" until it converges to the true ellipsoidal
     * geodesic.
     */
    private function distance(Coordinate $from, Coordinate $to): float
    {
        // 'a' = semi-major axis (equatorial radius), 'b' = semi-minor axis
        // (polar radius, derived from a and the flattening f), 'f' =
        // flattening. These three constants fully describe the WGS-84
        // ellipsoid.
        $a = self::SEMI_MAJOR_AXIS_M;
        $f = self::FLATTENING;
        $b = (1 - $f) * $a;

        // L = difference in longitude between the two points (radians).
        $bigL = deg2rad($to->lng - $from->lng);

        // U1, U2 = "reduced latitudes" — the latitudes the two points would
        // have on an auxiliary sphere that shares the ellipsoid's
        // flattening. Working in reduced latitude is what allows the
        // spherical trigonometry below to approximate the ellipsoidal
        // geometry.
        $u1 = atan((1 - $f) * tan(deg2rad($from->lat)));
        $u2 = atan((1 - $f) * tan(deg2rad($to->lat)));

        $sinU1 = sin($u1);
        $cosU1 = cos($u1);
        $sinU2 = sin($u2);
        $cosU2 = cos($u2);

        // lambda = difference in longitude on the auxiliary sphere,
        // initialised to L and refined by the iteration below.
        $lambda = $bigL;
        $iterationsLeft = self::MAX_ITERATIONS;

        // Working variables that are recomputed each iteration but also
        // needed *after* the loop for the final distance formula — they
        // must be declared outside the loop and retain their last value
        // once the loop exits (whether by convergence or by exhausting
        // $iterationsLeft).
        $cosSqAlpha = 0.0;
        $cos2SigmaM = 0.0;
        $sinSigma = 0.0;
        $cosSigma = 1.0;
        $sigma = 0.0;

        do {
            $sinLambda = sin($lambda);
            $cosLambda = cos($lambda);

            // sigma = angular separation between the two points on the
            // auxiliary sphere. sinSigma is computed first via the
            // spherical law of cosines / Pythagorean-style combination of
            // the two points' positions.
            $sinSigma = sqrt(
                ($cosU2 * $sinLambda) ** 2
                + ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda) ** 2
            );

            if ($sinSigma === 0.0) {
                // sinSigma === 0 means the two points are coincident (zero
                // angular separation) — short-circuit to avoid a
                // division-by-zero a few lines below (sinAlpha's
                // denominator) and return the obviously-correct answer.
                return 0.0;
            }

            $cosSigma = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLambda;
            $sigma = atan2($sinSigma, $cosSigma);

            // alpha = azimuth (compass bearing) of the geodesic at the
            // equator. cosSqAlpha = cos^2(alpha) is what's actually needed
            // for the corrections below.
            $sinAlpha = $cosU1 * $cosU2 * $sinLambda / $sinSigma;
            $cosSqAlpha = 1 - $sinAlpha ** 2;

            // cos2SigmaM = cos(2 * sigma_m), where sigma_m is the angular
            // distance from the equator to the midpoint of the geodesic.
            // When cosSqAlpha === 0 the geodesic runs exactly along the
            // equator (alpha = 90 deg); the natural formula would divide by
            // zero, so this special-cases the equatorial geodesic to 0.0
            // (its correct value).
            $cos2SigmaM = $cosSqAlpha !== 0.0
                ? $cosSigma - 2 * $sinU1 * $sinU2 / $cosSqAlpha
                : 0.0;

            // C is a correction coefficient (function of the flattening and
            // the geodesic's azimuth) applied to lambda's update below.
            $bigC = $f / 16 * $cosSqAlpha * (4 + $f * (4 - 3 * $cosSqAlpha));

            // Refine lambda using the corrected longitude-difference
            // formula. The loop repeats until lambda stops changing
            // meaningfully (CONVERGENCE_THRESHOLD) or MAX_ITERATIONS is
            // exhausted.
            $lambdaPrevious = $lambda;
            $lambda = $bigL + (1 - $bigC) * $f * $sinAlpha
                * ($sigma + $bigC * $sinSigma * ($cos2SigmaM + $bigC * $cosSigma * (-1 + 2 * $cos2SigmaM ** 2)));
        } while (abs($lambda - $lambdaPrevious) > self::CONVERGENCE_THRESHOLD && --$iterationsLeft > 0);

        // Final ellipsoidal correction: uSq, A and B are truncated series
        // expansions (in powers of uSq) that approximate the elliptic
        // integrals needed to convert the auxiliary-sphere angle `sigma`
        // into a true ellipsoidal arc length, without actually evaluating
        // elliptic integrals.
        $uSq = $cosSqAlpha * ($a ** 2 - $b ** 2) / $b ** 2;
        $bigA = 1 + $uSq / 16384 * (4096 + $uSq * (-768 + $uSq * (320 - 175 * $uSq)));
        $bigB = $uSq / 1024 * (256 + $uSq * (-128 + $uSq * (74 - 47 * $uSq)));

        // deltaSigma = higher-order correction to sigma itself.
        $deltaSigma = $bigB * $sinSigma * ($cos2SigmaM + $bigB / 4 * (
            $cosSigma * (-1 + 2 * $cos2SigmaM ** 2)
            - $bigB / 6 * $cos2SigmaM * (-3 + 4 * $sinSigma ** 2) * (-3 + 4 * $cos2SigmaM ** 2)
        ));

        // Final distance along the ellipsoid surface, in metres:
        // b * A * (sigma - deltaSigma).
        $distanceM = $b * $bigA * ($sigma - $deltaSigma);

        // Convert metres to kilometres to match the convention used by
        // DistanceMatrix and all other providers.
        return $distanceM / 1000.0;
    }
}
