<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;

/**
 * Local search: repeatedly reverses a segment of the tour whenever doing so
 * shortens the total distance, until no further improvement is found.
 *
 * 2-opt is the standard fix for the "crossing edges" that greedy
 * construction (e.g. {@see NearestNeighborStrategy}) tends to leave behind:
 * if two edges of the tour cross when drawn on a map, reversing the segment
 * between them removes the crossing and can only make the tour shorter or
 * equal. The algorithm tries every pair of edges and keeps any reversal
 * that strictly improves the total distance, repeating until no pair of
 * edges improves things any further (a "2-opt local optimum"). Full
 * write-up: {@see ../../doc/ALGORITHMS.md}.
 */
final class TwoOptOptimizer
{
    /**
     * Minimum improvement (in km) required to accept a reversal. Without
     * this, floating-point rounding noise around 0.0 could cause the outer
     * `while ($improved)` loop to oscillate forever between two
     * equal-length tours.
     */
    private const IMPROVEMENT_EPSILON = 1e-9;

    /**
     * Repeatedly scans every pair of positions `(i, j)` and reverses the
     * segment `tour[i..j]` whenever doing so reduces total distance
     * ({@see self::reversalDelta()}), until a full pass makes no
     * improvement at all (a local optimum).
     *
     * @param int[] $tour
     * @return int[]
     */
    public function optimize(DistanceMatrix $matrix, array $tour, SolveOptions $options): array
    {
        $size = count($tour);
        // Position 0 is never disturbed: it is either the fixed depot or, for
        // a free start, an arbitrary anchor (on a symmetric matrix reversal
        // symmetry makes this lossless; on an asymmetric one it merely
        // narrows the local search neighborhood — moves are still only
        // accepted when their true directed delta improves the tour).
        $firstMovable = 1;

        // Road-network matrices (OSRM/Google) are asymmetric: reversing a
        // segment also reverses every edge *inside* it, each of which may
        // have a different distance in the opposite direction. The delta
        // must then include those internal changes (O(segment) instead of
        // O(1)) — still using only the provider's directed route distances,
        // never falling back to straight-line math.
        $symmetric = $matrix->isSymmetric();

        $improved = true;

        // Keep making full passes over all (i, j) pairs as long as the
        // previous pass found at least one improving reversal. Each pass is
        // O(n^2) pairs x O(1) delta check (symmetric matrices; O(n) per
        // check for asymmetric ones); the number of passes until
        // convergence is typically small in practice.
        while ($improved) {
            $improved = false;

            for ($i = $firstMovable; $i < $size - 1; $i++) {
                for ($j = $i + 1; $j < $size; $j++) {
                    $delta = $this->reversalDelta($matrix, $tour, $i, $j, $options->returnToStart);

                    if (!$symmetric) {
                        $delta += $this->internalReversalDelta($matrix, $tour, $i, $j);
                    }

                    if ($delta < -self::IMPROVEMENT_EPSILON) {
                        $tour = $this->reverseSegment($tour, $i, $j);
                        $improved = true;
                    }
                }
            }
        }

        return $tour;
    }

    /**
     * Computes how the **boundary** edges' total would change (negative =
     * shorter = improvement) if the segment `tour[i..j]` were reversed in
     * place.
     *
     * Reversing `tour[i..j]` changes the edges *entering* and *leaving*
     * that segment; every edge strictly inside the segment is still
     * present, just traversed in the opposite direction. For a
     * **symmetric** matrix that internal direction flip is free, so this
     * boundary-only O(1) delta is the complete answer. For an
     * **asymmetric** matrix the caller must add
     * {@see self::internalReversalDelta()} on top. The boundary
     * computation compares just the old vs. new boundary edges, without
     * rebuilding or re-measuring the whole tour:
     *
     *   ... -> before -> [a ... b] -> after -> ...   (current)
     *   ... -> before -> [b ... a] -> after -> ...   (after reversing i..j)
     *
     * delta = (new edges before+after) - (old edges before+after).
     *
     * @param int[] $tour
     */
    private function reversalDelta(DistanceMatrix $matrix, array $tour, int $i, int $j, bool $returnToStart): float
    {
        $size = count($tour);
        $before = $tour[$i - 1];
        $a = $tour[$i];
        $b = $tour[$j];

        if ($j + 1 >= $size && !$returnToStart) {
            // $b is the last stop of an open path: only the leg entering the
            // segment changes, there is no "after" leg to recompute.
            $removed = $matrix->distanceBetween($before, $a);
            $added = $matrix->distanceBetween($before, $b);

            return $added - $removed;
        }

        // For a closed loop (returnToStart), `($j + 1) % $size` wraps
        // around to the first stop — the edge closing the loop is treated
        // like any other edge.
        $after = $tour[($j + 1) % $size];
        $removed = $matrix->distanceBetween($before, $a) + $matrix->distanceBetween($b, $after);
        $added = $matrix->distanceBetween($before, $b) + $matrix->distanceBetween($a, $after);

        return $added - $removed;
    }

    /**
     * The additional cost change from traversing every edge *inside*
     * `tour[i..j]` in the opposite direction after a reversal — zero by
     * definition on a symmetric matrix, but on a road network (one-way
     * streets) each `k -> k+1` leg is replaced by the potentially very
     * different `k+1 -> k` leg. O(j - i) directed matrix lookups.
     *
     * @param int[] $tour
     */
    private function internalReversalDelta(DistanceMatrix $matrix, array $tour, int $i, int $j): float
    {
        $delta = 0.0;

        for ($k = $i; $k < $j; $k++) {
            $delta += $matrix->distanceBetween($tour[$k + 1], $tour[$k])
                - $matrix->distanceBetween($tour[$k], $tour[$k + 1]);
        }

        return $delta;
    }

    /**
     * Returns a copy of `$tour` with the elements at positions `i..j`
     * (inclusive) in reverse order, leaving everything outside that range
     * untouched.
     *
     * @param int[] $tour
     * @return int[]
     */
    private function reverseSegment(array $tour, int $i, int $j): array
    {
        $segment = array_reverse(array_slice($tour, $i, $j - $i + 1));
        array_splice($tour, $i, $j - $i + 1, $segment);

        return $tour;
    }
}
