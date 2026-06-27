<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;

/**
 * Shared pure-math helpers for tour search strategies. A "tour" is an array
 * of stop indices (a permutation of 0..n-1).
 *
 * Two responsibilities live here because every search strategy in this
 * package needs both: measuring a tour's total length
 * ({@see self::distance()}), and recognizing when two differently-generated
 * tours are actually "the same route" so duplicates can be collapsed
 * ({@see self::normalize()} + {@see self::key()}, used by
 * {@see TopNRouteSolver::dedupeAndSort()}).
 */
final class TourMath
{
    private function __construct()
    {
    }

    /**
     * Sums the distance of each consecutive leg `tour[i] -> tour[i+1]`,
     * plus (if `$returnToStart`) the closing leg from the last stop back to
     * `tour[0]`. This is the objective function every search strategy in
     * this package is trying to minimize.
     *
     * @param int[] $tour
     */
    public static function distance(DistanceMatrix $matrix, array $tour, bool $returnToStart): float
    {
        $total = 0.0;
        $size = count($tour);

        for ($i = 0; $i < $size - 1; $i++) {
            $total += $matrix->distanceBetween($tour[$i], $tour[$i + 1]);
        }

        if ($returnToStart && $size > 1) {
            $total += $matrix->distanceBetween($tour[$size - 1], $tour[0]);
        }

        return $total;
    }

    /**
     * Returns a canonical representation of a tour so that routes which are
     * equivalent (same directed edges, same total distance) collapse to the
     * same key during deduplication.
     *
     * "Equivalent" depends on the route's shape **and** on whether the
     * distance matrix is symmetric (`$symmetricDistances`):
     * - An **open path** (no return to start) traversed forward or
     *   backward uses the same edges — but in *opposite directions*. On a
     *   symmetric matrix that's the same route; on an asymmetric road
     *   network (one-way streets) the two directions are genuinely
     *   different routes with different lengths and must NOT be collapsed.
     *   A fixed depot pins down which end is the "start" in either case.
     * - A **closed loop** (return to start) can additionally be *rotated*
     *   to start at any stop without changing its directed edges — so
     *   rotations are always equivalent, but the loop's *reversal* is only
     *   equivalent on a symmetric matrix.
     *
     * Among the representations that are equivalent under these rules,
     * normalization picks the lexicographically smallest
     * ({@see self::lexMin()}) so that equivalent tours always normalize to
     * the *identical* array, making `===` (via {@see self::key()})
     * sufficient for deduplication.
     *
     * @param int[] $tour
     * @return int[]
     */
    public static function normalize(array $tour, SolveOptions $options, bool $symmetricDistances = true): array
    {
        if ($options->depotIndex !== null) {
            if (!$options->returnToStart || count($tour) <= 1 || !$symmetricDistances) {
                // Open path with a fixed start, a single stop, or directed
                // (asymmetric) distances: no equivalent reordering.
                return $tour;
            }

            // Closed loop with a fixed depot, symmetric distances: the
            // depot's position (index 0) is fixed, but traversing the rest
            // of the loop in the opposite direction visits the same stops
            // via the same edges — i.e. it's the same physical route.
            $reversedTail = array_reverse(array_slice($tour, 1));

            return self::lexMin($tour, [$tour[0], ...$reversedTail]);
        }

        if ($options->returnToStart) {
            // Closed loop, free start: any rotation of the tour is the same
            // loop (identical directed edges). Its reversal is only the
            // same route when distances are symmetric.
            $rotated = self::rotateToSmallestFirst($tour);

            if (!$symmetricDistances) {
                return $rotated;
            }

            $reversedRotated = self::rotateToSmallestFirst(array_reverse($tour));

            return self::lexMin($rotated, $reversedRotated);
        }

        if (!$symmetricDistances) {
            // Open path, free start, directed distances: forward and
            // reverse are different routes — keep as-is.
            return $tour;
        }

        // Open path, free start, symmetric distances: forward and reverse
        // traversal are the same route.
        return self::lexMin($tour, array_reverse($tour));
    }

    /**
     * Converts a normalized tour into a string suitable for use as an array
     * key (e.g. `"0,2,1,3"`), so identical normalized tours can be detected
     * via simple array-key lookups.
     *
     * @param int[] $tour
     */
    public static function key(array $tour): string
    {
        return implode(',', $tour);
    }

    /**
     * Rotates `$tour` so that its smallest element comes first, preserving
     * the cyclic order of the rest. For a closed loop, this gives every
     * rotation of the same loop an identical starting point, which is a
     * prerequisite for comparing rotations with {@see self::lexMin()}.
     *
     * @param int[] $tour
     * @return int[]
     */
    private static function rotateToSmallestFirst(array $tour): array
    {
        $minPosition = array_keys($tour, min($tour), true)[0];

        return [...array_slice($tour, $minPosition), ...array_slice($tour, 0, $minPosition)];
    }

    /**
     * Returns whichever of `$a` or `$b` is lexicographically smaller —
     * i.e. compares element by element and returns the array with the
     * smaller value at the first position where they differ. Used to pick
     * a single, deterministic canonical form between two array
     * representations that describe the same physical route (e.g. a tour
     * and its reversal).
     *
     * @param int[] $a
     * @param int[] $b
     * @return int[]
     */
    private static function lexMin(array $a, array $b): array
    {
        $size = count($a);

        for ($i = 0; $i < $size; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] < $b[$i] ? $a : $b;
            }
        }

        return $a;
    }
}
