<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;

/**
 * Exhaustive search: evaluates every permutation of stops. Guarantees the
 * true optimum and genuinely distinct runners-up. Only suitable for small
 * problem sizes (n <= ~10), since the cost grows with n!.
 *
 * Used by {@see TopNRouteSolver} when `n <= TopNRouteSolver::EXACT_LIMIT`
 * (10), where 10! = 3,628,800 permutations is still fast enough. Full
 * write-up: {@see ../../doc/ALGORITHMS.md}.
 */
final class PermutationSolver
{
    /**
     * Evaluates the total distance of every valid permutation of stop
     * indices `0..n-1` (filtering out any whose first stop isn't the fixed
     * depot, if one is set) and returns them all — unsorted and
     * un-deduplicated; {@see TopNRouteSolver::dedupeAndSort()} handles
     * that afterwards.
     *
     * @return array{tour: int[], totalDistanceKm: float}[]
     */
    public function solve(DistanceMatrix $matrix, SolveOptions $options): array
    {
        $size = $matrix->size();
        $results = [];

        foreach (self::permutations(range(0, $size - 1)) as $tour) {
            if ($options->depotIndex !== null && $tour[0] !== $options->depotIndex) {
                // Skip permutations that don't start at the fixed depot —
                // cheaper to filter here than to generate only
                // depot-starting permutations directly.
                continue;
            }

            $results[] = [
                'tour' => $tour,
                'totalDistanceKm' => TourMath::distance($matrix, $tour, $options->returnToStart),
            ];
        }

        return $results;
    }

    /**
     * Recursively generates every ordering of `$items` (a "permutation
     * tree"): for each item, place it first and recursively permute
     * everything else. Implemented as a generator (`yield`) so the full
     * set of n! permutations is never held in memory at once.
     *
     * @param int[] $items
     * @return iterable<int[]>
     */
    private static function permutations(array $items): iterable
    {
        if (count($items) <= 1) {
            // Base case: a single item (or none) has exactly one ordering.
            yield $items;

            return;
        }

        foreach ($items as $position => $item) {
            // Try each item as the "first" element of the permutation, and
            // recursively permute everything that remains.
            $remaining = $items;
            unset($remaining[$position]);

            foreach (self::permutations(array_values($remaining)) as $permutation) {
                yield [$item, ...$permutation];
            }
        }
    }
}
