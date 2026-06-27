<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\Leg;
use Genaker\Bundle\ComiVoyager\Core\Model\Route;
use Genaker\Bundle\ComiVoyager\Core\Model\RouteCollection;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Model\Stop;

/**
 * Orchestrates the search strategies to produce the top-N ranked routes:
 * exhaustive permutations for small inputs, Held-Karp + a heuristic pool for
 * medium inputs, and nearest-neighbor + 2-opt + Or-opt with restarts for
 * larger inputs.
 *
 * This is the heart of the "comivoyager" (TSP-solver) logic. A "tour" is
 * represented throughout as a plain `int[]` — a permutation of address
 * indices `0..n-1` — and only converted into the richer Route/Stop/Leg
 * model at the very end, in {@see self::buildRoute()}. Keeping the search
 * itself in terms of cheap integer arrays is what makes it feasible to
 * generate and compare many candidate tours.
 *
 * Full algorithm-by-algorithm write-up (complexity, pros/cons, when each
 * strategy kicks in): {@see ../../doc/ALGORITHMS.md}.
 */
final class TopNRouteSolver
{
    /**
     * n! grows fast: measured on this codebase (haversine matrix, PHP 8),
     * exhaustive search takes ~0.2s/67MB at n=8, ~3.4s/425MB at n=9, and
     * ~7min/3.9GB at n=10 — so 9 is the hard ceiling for a synchronous HTTP
     * request. At or below this size, PermutationSolver evaluates every
     * possible tour and is used directly (it doubles as the "ground truth"
     * the other strategies are compared against in tests). Above it,
     * HeldKarpSolver still finds the exact optimum up to HELD_KARP_LIMIT
     * in milliseconds; only the ranked runners-up become heuristic.
     */
    private const EXACT_LIMIT = 9;

    /**
     * Held-Karp's O(2^n * n^2) dynamic programming is exact but its memory
     * (2^n * n table entries) and runtime become impractical beyond ~15
     * stops. Between EXACT_LIMIT and this limit, Held-Karp's single optimal
     * tour is added to the heuristic candidate pool below.
     */
    private const HELD_KARP_LIMIT = 15;

    /**
     * Above HELD_KARP_LIMIT, only heuristics are used. In addition to one
     * nearest-neighbor tour per possible start (or just the depot, if
     * fixed), this many additional random-shuffle starting tours are
     * generated and refined — extra "shots on goal" that occasionally find
     * a better local optimum than any nearest-neighbor start, and help
     * produce genuinely different runner-up routes.
     */
    private const RANDOM_RESTART_COUNT = 5;

    public function __construct(
        private readonly PermutationSolver $permutationSolver = new PermutationSolver(),
        private readonly HeldKarpSolver $heldKarpSolver = new HeldKarpSolver(),
        private readonly NearestNeighborStrategy $nearestNeighbor = new NearestNeighborStrategy(),
        private readonly TwoOptOptimizer $twoOpt = new TwoOptOptimizer(),
        private readonly OrOptOptimizer $orOpt = new OrOptOptimizer(),
    ) {
    }

    /**
     * Top-level entry point: generates a pool of candidate tours
     * (size-dependent strategy, see {@see self::collectCandidates()}),
     * deduplicates/sorts them by total distance, keeps the best `$count`,
     * and converts each into a full {@see Route} (with per-leg distances
     * and cumulative totals) ranked best-first.
     *
     * @param Address[] $addresses
     */
    public function solve(array $addresses, DistanceMatrix $matrix, int $count, SolveOptions $options): RouteCollection
    {
        $candidates = $this->dedupeAndSort($this->collectCandidates($matrix, $options), $matrix, $options);
        $top = array_slice($candidates, 0, max(1, $count));
        // After sorting, the first candidate is always the shortest —
        // every other route's "delta from best" is measured against it.
        $bestDistance = $top[0]['totalDistanceKm'];

        $routes = [];

        foreach ($top as $position => $candidate) {
            $route = $this->buildRoute($addresses, $matrix, $candidate['tour'], $candidate['totalDistanceKm'], $options);
            $route->rank = $position + 1;
            $route->isShortest = $position === 0;
            $route->deltaFromBestKm = $candidate['totalDistanceKm'] - $bestDistance;
            $routes[] = $route;
        }

        return new RouteCollection($routes, shortestIndex: 0, requestedCount: $count);
    }

    /**
     * Generates a pool of candidate tours, choosing the search strategy
     * based on problem size `n = $matrix->size()`:
     *
     * - `n <= EXACT_LIMIT` (10): exhaustive {@see PermutationSolver} — every
     *   permutation, guaranteed optimal and exact runners-up. Returned
     *   immediately (no need for the heuristic pool below).
     * - `n <= HELD_KARP_LIMIT` (15): the exact {@see HeldKarpSolver} tour is
     *   added as one candidate, *plus* the heuristic pool below (so there
     *   are still multiple distinct candidates to rank, not just the single
     *   optimum).
     * - `n > HELD_KARP_LIMIT`: heuristic pool only.
     *
     * The heuristic pool consists of:
     *   1. One {@see NearestNeighborStrategy} tour per possible starting
     *      stop (or just the fixed depot, if `$options->depotIndex` is
     *      set), each refined by {@see self::refine()}.
     *   2. {@see self::RANDOM_RESTART_COUNT} additional tours built from a
     *      random shuffle (with the depot moved back to the front if
     *      fixed), also refined.
     *
     * @return array{tour: int[], totalDistanceKm: float}[]
     */
    private function collectCandidates(DistanceMatrix $matrix, SolveOptions $options): array
    {
        $size = $matrix->size();

        if ($size <= self::EXACT_LIMIT) {
            return $this->permutationSolver->solve($matrix, $options);
        }

        $candidates = [];

        if ($size <= self::HELD_KARP_LIMIT) {
            $candidates[] = $this->heldKarpSolver->solve($matrix, $options);
        }

        // One nearest-neighbor tour per candidate starting stop. If a depot
        // is fixed, only that stop may be the start.
        $startCandidates = $options->depotIndex !== null ? [$options->depotIndex] : range(0, $size - 1);

        foreach ($startCandidates as $start) {
            $tour = $this->refine($matrix, $this->nearestNeighbor->buildTour($matrix, $start), $options);
            $candidates[] = [
                'tour' => $tour,
                'totalDistanceKm' => TourMath::distance($matrix, $tour, $options->returnToStart),
            ];
        }

        // A handful of randomized restarts add diversity beyond the
        // deterministic nearest-neighbor starting points, helping surface
        // genuinely distinct runners-up.
        for ($attempt = 0; $attempt < self::RANDOM_RESTART_COUNT; $attempt++) {
            $tour = range(0, $size - 1);
            shuffle($tour);

            if ($options->depotIndex !== null) {
                $tour = $this->moveToFront($tour, $options->depotIndex);
            }

            $tour = $this->refine($matrix, $tour, $options);
            $candidates[] = [
                'tour' => $tour,
                'totalDistanceKm' => TourMath::distance($matrix, $tour, $options->returnToStart),
            ];
        }

        return $candidates;
    }

    /**
     * Applies local-search improvement to a tour: first 2-opt (removes
     * crossing edges by reversing segments — see {@see TwoOptOptimizer}),
     * then Or-opt (relocates short segments — see {@see OrOptOptimizer}).
     * Each runs to a local optimum before the next starts; running 2-opt
     * first is conventional since it tends to remove the largest, most
     * obvious inefficiencies before the finer-grained Or-opt relocations.
     *
     * @param int[] $tour
     * @return int[]
     */
    private function refine(DistanceMatrix $matrix, array $tour, SolveOptions $options): array
    {
        $tour = $this->twoOpt->optimize($matrix, $tour, $options);

        return $this->orOpt->optimize($matrix, $tour, $options);
    }

    /**
     * Rotates `$tour` so that `$value` becomes the first element, preserving
     * the relative order of everything else. Used to put a fixed depot back
     * at position 0 after `shuffle()` has scrambled the tour — position 0
     * is the contract every solver/optimizer relies on for "where the route
     * starts".
     *
     * @param int[] $tour
     * @return int[]
     */
    private function moveToFront(array $tour, int $value): array
    {
        $position = array_search($value, $tour, true);
        $item = $tour[$position];
        unset($tour[$position]);

        return [$item, ...array_values($tour)];
    }

    /**
     * Collapses candidates that represent the same physical route (same
     * stops in the same order, possibly reversed/rotated depending on
     * {@see TourMath::normalize()} — which only treats a reversal as
     * equivalent when the matrix is symmetric) into one entry, then sorts
     * the remaining unique candidates by total distance, best (shortest)
     * first.
     *
     * The stored distance is always **recomputed from the normalized tour**
     * rather than carried over from the candidate: normalization may have
     * picked a different (reversed/rotated) representative, and on an
     * asymmetric road network a reversed tour has a different length — the
     * distance returned to callers must match the tour actually returned.
     *
     * Deduplication matters because nearest-neighbor + refinement from
     * different starting points frequently converges onto the *same* local
     * optimum; without this step the "top 3 routes" could just be the same
     * route shown three times.
     *
     * @param array{tour: int[], totalDistanceKm: float}[] $candidates
     * @return array{tour: int[], totalDistanceKm: float}[]
     */
    private function dedupeAndSort(array $candidates, DistanceMatrix $matrix, SolveOptions $options): array
    {
        $symmetric = $matrix->isSymmetric();
        $byKey = [];

        foreach ($candidates as $candidate) {
            $normalized = TourMath::normalize($candidate['tour'], $options, $symmetric);
            $key = TourMath::key($normalized);

            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'tour' => $normalized,
                    'totalDistanceKm' => TourMath::distance($matrix, $normalized, $options->returnToStart),
                ];
            }
        }

        $result = array_values($byKey);

        usort($result, static fn (array $a, array $b): int => $a['totalDistanceKm'] <=> $b['totalDistanceKm']);

        return $result;
    }

    /**
     * Converts a raw `int[]` tour (a permutation of address indices) into a
     * full {@see Route}: a sequence of {@see Stop}s, each linked to the
     * {@see Leg} (distance + running total) from the previous stop. If
     * `$options->returnToStart` is set, an extra closing leg/stop back to
     * the first address is appended.
     *
     * @param Address[] $addresses
     * @param int[] $tour
     */
    private function buildRoute(array $addresses, DistanceMatrix $matrix, array $tour, float $totalDistanceKm, SolveOptions $options): Route
    {
        $stops = [];
        $legs = [];
        $cumulative = 0.0;
        $size = count($tour);

        foreach ($tour as $position => $addressIndex) {
            $leg = null;

            if ($position > 0) {
                $previousIndex = $tour[$position - 1];
                $distance = $matrix->distanceBetween($previousIndex, $addressIndex);
                $cumulative += $distance;
                $leg = new Leg($previousIndex, $addressIndex, $distance, $cumulative);
                $legs[] = $leg;
            }

            $stops[] = new Stop(
                sequence: $position + 1,
                address: $addresses[$addressIndex],
                legFromPrevious: $leg,
                isStart: $position === 0,
                isEnd: $position === $size - 1 && !$options->returnToStart,
            );
        }

        if ($options->returnToStart && $size > 1) {
            $distance = $matrix->distanceBetween($tour[$size - 1], $tour[0]);
            $cumulative += $distance;
            $leg = new Leg($tour[$size - 1], $tour[0], $distance, $cumulative);
            $legs[] = $leg;

            $stops[] = new Stop(
                sequence: $size + 1,
                address: $addresses[$tour[0]],
                legFromPrevious: $leg,
                isStart: false,
                isEnd: true,
            );
        }

        return new Route($stops, $legs, $totalDistanceKm);
    }
}
