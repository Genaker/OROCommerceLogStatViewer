<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;

/**
 * Builds an initial tour by repeatedly stepping to the closest unvisited stop.
 *
 * This is the classic "greedy" TSP construction heuristic: cheap to compute
 * (O(n^2)) and usually within 25% of optimal, but can leave one or two very
 * long "leftover" edges where the greedy choices painted the route into a
 * corner — exactly what {@see TwoOptOptimizer} and {@see OrOptOptimizer}
 * are run afterwards to fix. Full write-up: {@see ../../doc/ALGORITHMS.md}.
 */
final class NearestNeighborStrategy
{
    /**
     * Starting from `$start`, repeatedly appends whichever unvisited stop
     * is closest to the current last stop, until every stop has been
     * visited exactly once.
     *
     * @return int[] a permutation of `0..size-1` beginning with `$start`
     */
    public function buildTour(DistanceMatrix $matrix, int $start): array
    {
        $size = $matrix->size();
        $visited = array_fill(0, $size, false);
        $visited[$start] = true;

        $tour = [$start];
        $current = $start;

        for ($step = 1; $step < $size; $step++) {
            $nearest = null;
            $nearestDistance = \INF;

            // Linear scan over all stops to find the closest one not yet
            // visited. O(n) per step, O(n^2) overall for the whole tour.
            for ($candidate = 0; $candidate < $size; $candidate++) {
                if ($visited[$candidate]) {
                    continue;
                }

                $distance = $matrix->distanceBetween($current, $candidate);

                if ($distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearest = $candidate;
                }
            }

            $tour[] = $nearest;
            $visited[$nearest] = true;
            $current = $nearest;
        }

        return $tour;
    }
}
