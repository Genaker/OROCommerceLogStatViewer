<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;

/**
 * Orders a single vehicle's delivery stops into an efficient sequence,
 * with full control over the start and end of the route:
 *
 *   - fixed start (the driver's home / the warehouse)
 *   - round trip back to start, OR
 *   - open route ending at the last delivery, OR
 *   - open route ending at an explicit end point (e.g. driver's home
 *     when it differs from the loading depot).
 *
 * Uses nearest-neighbour construction followed by 2-opt improvement.
 * All distances are in kilometres (the unit returned by the distance
 * providers). Cluster sizes in VRP are small (typically 5–50 stops),
 * so 2-opt's O(n²) per pass is cheap.
 */
final class RouteSequencer
{
    private const MAX_2OPT_PASSES = 30;

    public function __construct(
        private readonly DistanceMatrixProviderInterface $distanceProvider,
    ) {
    }

    /**
     * @param Coordinate[] $stops Delivery points to order.
     * @param Coordinate $start Where the route begins.
     * @param Coordinate|null $end Explicit end point, or null.
     * @param bool $returnToStart If true, the route returns to $start.
     * @return array{order: int[], distanceKm: float, legsKm: float[], finalLegKm: float}
     *         order      = indices into $stops, in visiting sequence;
     *         legsKm[i]  = distance from the previous point to order[i]
     *                      (legsKm[0] = start → first stop);
     *         finalLegKm = distance from the last stop to the end point
     *                      (0.0 for an open route with no end).
     */
    public function sequence(array $stops, Coordinate $start, ?Coordinate $end, bool $returnToStart): array
    {
        $n = count($stops);
        if ($n === 0) {
            return ['order' => [], 'distanceKm' => 0.0, 'legsKm' => [], 'finalLegKm' => 0.0];
        }

        // Build the point list: [start, stops..., (end if a distinct fixed end)]
        $points = [$start];
        foreach ($stops as $stop) {
            $points[] = $stop;
        }

        $startIdx = 0;
        $stopIdxs = range(1, $n); // matrix indices of the stops

        $endIdx = null;
        if ($returnToStart) {
            $endIdx = $startIdx; // close the loop back to start
        } elseif ($end !== null) {
            $points[] = $end;
            $endIdx = $n + 1;
        }
        // else: open route, no end node — finish at the last delivery

        $matrix = $this->distanceProvider->build($points);

        // Nearest-neighbour construction over the stop indices.
        $order = $this->nearestNeighbour($matrix, $startIdx, $stopIdxs, $endIdx);

        // 2-opt improvement.
        $order = $this->twoOpt($matrix, $startIdx, $order, $endIdx);

        // Per-leg distances: start → stop0, stop0 → stop1, ...
        $legsKm = [];
        $prev = $startIdx;
        foreach ($order as $idx) {
            $legsKm[] = $matrix->distanceBetween($prev, $idx);
            $prev = $idx;
        }
        $finalLegKm = $endIdx !== null ? $matrix->distanceBetween($prev, $endIdx) : 0.0;
        $distanceKm = array_sum($legsKm) + $finalLegKm;

        // Map matrix indices back to 0-based indices into $stops.
        $mapped = array_map(static fn (int $mi): int => $mi - 1, $order);

        return [
            'order'      => $mapped,
            'distanceKm' => $distanceKm,
            'legsKm'     => $legsKm,
            'finalLegKm' => $finalLegKm,
        ];
    }

    /**
     * @param int[] $stopIdxs
     * @return int[] ordered stop matrix indices
     */
    private function nearestNeighbour(\Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix $matrix, int $startIdx, array $stopIdxs, ?int $endIdx): array
    {
        $unvisited = $stopIdxs;
        $order = [];
        $current = $startIdx;

        while (!empty($unvisited)) {
            $best = null;
            $bestDist = PHP_FLOAT_MAX;
            foreach ($unvisited as $key => $idx) {
                $d = $matrix->distanceBetween($current, $idx);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $key;
                }
            }
            $order[] = $unvisited[$best];
            $current = $unvisited[$best];
            unset($unvisited[$best]);
        }

        return $order;
    }

    /**
     * @param int[] $order
     * @return int[]
     */
    private function twoOpt(\Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix $matrix, int $startIdx, array $order, ?int $endIdx): array
    {
        $n = count($order);
        if ($n < 3) {
            return $order;
        }

        $bestCost = $this->routeCost($matrix, $startIdx, $order, $endIdx);

        for ($pass = 0; $pass < self::MAX_2OPT_PASSES; $pass++) {
            $improved = false;

            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $candidate = $order;
                    // Reverse the segment [i..j].
                    $segment = array_slice($candidate, $i, $j - $i + 1);
                    $segment = array_reverse($segment);
                    array_splice($candidate, $i, $j - $i + 1, $segment);

                    $cost = $this->routeCost($matrix, $startIdx, $candidate, $endIdx);
                    if ($cost + 1e-9 < $bestCost) {
                        $order = $candidate;
                        $bestCost = $cost;
                        $improved = true;
                    }
                }
            }

            if (!$improved) {
                break;
            }
        }

        return $order;
    }

    /**
     * Total route distance: start → stops (in order) → end (if any).
     *
     * @param int[] $order
     */
    private function routeCost(\Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix $matrix, int $startIdx, array $order, ?int $endIdx): float
    {
        $total = 0.0;
        $prev = $startIdx;
        foreach ($order as $idx) {
            $total += $matrix->distanceBetween($prev, $idx);
            $prev = $idx;
        }
        if ($endIdx !== null) {
            $total += $matrix->distanceBetween($prev, $endIdx);
        }
        return $total;
    }
}
