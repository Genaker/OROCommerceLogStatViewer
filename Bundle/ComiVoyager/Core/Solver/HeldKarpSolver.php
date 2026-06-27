<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;

/**
 * Exact TSP via the Held-Karp dynamic programming algorithm, O(2^n * n^2).
 * Feasible up to roughly 15-18 stops, where plain permutation enumeration
 * would be too slow.
 *
 * Held-Karp avoids recomputing overlapping subproblems shared across many
 * permutations (the same insight as memoized recursion / classic DP):
 * instead of n! full tours, it considers every (subset of stops visited,
 * last stop) pair at most once — 2^n subsets x n possible "last stops".
 *
 * Full write-up (complexity, comparison with PermutationSolver and the
 * heuristic strategies): {@see ../../doc/ALGORITHMS.md}.
 */
final class HeldKarpSolver
{
    /**
     * Computes the single optimal tour visiting every stop exactly once,
     * honoring `$options`:
     *
     * - **Fixed depot**: the tour starts at `$options->depotIndex`.
     * - **Free-start closed loop**: the start is pinned to stop 0 — every
     *   rotation of a loop uses the same directed edges, so this loses no
     *   generality.
     * - **Free-start open path**: the optimal path's start is *unknown* —
     *   pinning it would silently exclude better paths starting elsewhere.
     *   Solved exactly via {@see self::solveFreeStartOpenPath()} (virtual
     *   depot trick).
     *
     * @return array{tour: int[], totalDistanceKm: float}
     */
    public function solve(DistanceMatrix $matrix, SolveOptions $options): array
    {
        if ($options->depotIndex === null && !$options->returnToStart && $matrix->size() >= 2) {
            return $this->solveFreeStartOpenPath($matrix);
        }

        return $this->solveFrom($matrix, $options->depotIndex ?? 0, $options->returnToStart);
    }

    /**
     * Exact free-endpoint open path via the standard **virtual depot**
     * trick: append a phantom stop with zero-cost edges to and from every
     * real stop, solve the *closed loop* fixed at the phantom (exact — a
     * loop's start is rotation-invariant), then drop the phantom. The
     * phantom's two zero-cost legs are exactly the "teleports" into the
     * path's true first stop and out of its true last stop, so the loop's
     * cost equals the open path's cost and both endpoints are chosen
     * optimally. Same O(2^n * n^2) complexity, one extra node.
     *
     * @return array{tour: int[], totalDistanceKm: float}
     */
    private function solveFreeStartOpenPath(DistanceMatrix $matrix): array
    {
        $size = $matrix->size();
        $virtual = $size;

        $distances = $matrix->toArray();
        $distances[$virtual] = array_fill(0, $size + 1, 0.0);

        for ($i = 0; $i < $size; $i++) {
            $distances[$i][$virtual] = 0.0;
        }

        $closedLoop = $this->solveFrom(new DistanceMatrix($distances), $virtual, true);

        // The loop is [virtual, p1, ..., pn]; stripping the virtual depot
        // leaves the optimal open path p1..pn at the same total cost.
        return [
            'tour' => array_slice($closedLoop['tour'], 1),
            'totalDistanceKm' => $closedLoop['totalDistanceKm'],
        ];
    }

    /**
     * Held-Karp DP with a fixed starting stop, optionally closing the loop
     * back to it.
     *
     * @return array{tour: int[], totalDistanceKm: float}
     */
    private function solveFrom(DistanceMatrix $matrix, int $start, bool $returnToStart): array
    {
        $size = $matrix->size();

        if ($size === 1) {
            return ['tour' => [$start], 'totalDistanceKm' => 0.0];
        }

        // $others = every stop except the fixed start, reindexed to
        // 0..count-1 so each can be represented by a single bit in a
        // bitmask. $fullMask has all $count bits set — the state where
        // every "other" stop has been visited.
        $others = array_values(array_filter(range(0, $size - 1), static fn (int $i): bool => $i !== $start));
        $count = count($others);
        $fullMask = (1 << $count) - 1;

        // dp[mask][i] = cheapest cost of a path that starts at $start, visits
        // exactly the nodes whose bits are set in $mask (all from $others),
        // and ends at $others[$i]. parent[mask][i] tracks the previous node's
        // position within $others for path reconstruction.
        $dp = array_fill(0, $fullMask + 1, []);
        $parent = array_fill(0, $fullMask + 1, []);

        // Base case: paths of length 1 — directly from $start to each
        // single "other" stop.
        for ($i = 0; $i < $count; $i++) {
            $mask = 1 << $i;
            $dp[$mask][$i] = $matrix->distanceBetween($start, $others[$i]);
            $parent[$mask][$i] = -1;
        }

        // Build up paths of increasing length: for every (mask, i) state
        // already known, try extending it by one more unvisited stop $j.
        // Iterating $mask from 1 upward guarantees that whenever we look at
        // dp[$mask][$i], all states with fewer bits set (shorter paths)
        // have already been computed.
        for ($mask = 1; $mask <= $fullMask; $mask++) {
            for ($i = 0; $i < $count; $i++) {
                if (!($mask & (1 << $i)) || !isset($dp[$mask][$i])) {
                    // Either stop $i isn't part of this subset, or this
                    // (mask, i) state was never reached — skip.
                    continue;
                }

                $cost = $dp[$mask][$i];

                for ($j = 0; $j < $count; $j++) {
                    if ($mask & (1 << $j)) {
                        // $j already visited in this subset — can't revisit.
                        continue;
                    }

                    $nextMask = $mask | (1 << $j);
                    $candidate = $cost + $matrix->distanceBetween($others[$i], $others[$j]);

                    // Keep only the cheapest way to reach (nextMask, j);
                    // record $i as the predecessor for path reconstruction.
                    if (!isset($dp[$nextMask][$j]) || $candidate < $dp[$nextMask][$j]) {
                        $dp[$nextMask][$j] = $candidate;
                        $parent[$nextMask][$j] = $i;
                    }
                }
            }
        }

        // All "other" stops visited (mask = $fullMask) — pick whichever
        // last stop gives the cheapest total, adding the closing leg back
        // to $start if the route must form a loop.
        $bestEnd = -1;
        $bestCost = \INF;

        for ($i = 0; $i < $count; $i++) {
            $cost = $dp[$fullMask][$i] ?? \INF;

            if ($returnToStart) {
                $cost += $matrix->distanceBetween($others[$i], $start);
            }

            if ($cost < $bestCost) {
                $bestCost = $cost;
                $bestEnd = $i;
            }
        }

        // Walk the parent[] pointers backward from the best final state to
        // reconstruct the visiting order, then reverse it (and prepend
        // $start) to get the tour in forward order.
        $path = [];
        $mask = $fullMask;
        $current = $bestEnd;

        while ($current !== -1) {
            $path[] = $others[$current];
            $previous = $parent[$mask][$current];
            $mask &= ~(1 << $current);
            $current = $previous;
        }

        return ['tour' => [$start, ...array_reverse($path)], 'totalDistanceKm' => $bestCost];
    }
}
