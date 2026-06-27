<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\TopNRouteSolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Solver\TopNRouteSolver
 */
final class TopNRouteSolverTest extends TestCase
{
    /**
     * Four points on a line at positions 0, 1, 2, 3 -> distance(i, j) = |i - j|.
     */
    private function lineMatrix(): DistanceMatrix
    {
        return new DistanceMatrix([
            [0.0, 1.0, 2.0, 3.0],
            [1.0, 0.0, 1.0, 2.0],
            [2.0, 1.0, 0.0, 1.0],
            [3.0, 2.0, 1.0, 0.0],
        ]);
    }

    /**
     * @return Address[]
     */
    private function addresses(): array
    {
        return [
            new Address('A', new Coordinate(0.0, 0.0)),
            new Address('B', new Coordinate(0.0, 0.0)),
            new Address('C', new Coordinate(0.0, 0.0)),
            new Address('D', new Coordinate(0.0, 0.0)),
        ];
    }

    /**
     * `$n` points evenly spaced on a unit circle -> real Euclidean
     * distances. Used to exercise the heuristic path (`n >
     * TopNRouteSolver::HELD_KARP_LIMIT`, i.e. NearestNeighbor + 2-opt +
     * Or-opt + random restarts) with a *known* optimum: for points in
     * convex position, the unique 2-opt-optimal tour is the angular
     * (convex-hull) order, so the result is deterministic despite the
     * random restarts.
     */
    private function circleMatrix(int $n): DistanceMatrix
    {
        $coordinates = [];

        for ($i = 0; $i < $n; $i++) {
            $angle = 2 * M_PI * $i / $n;
            $coordinates[$i] = [cos($angle), sin($angle)];
        }

        $matrix = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $dx = $coordinates[$i][0] - $coordinates[$j][0];
                $dy = $coordinates[$i][1] - $coordinates[$j][1];
                $matrix[$i][$j] = sqrt($dx * $dx + $dy * $dy);
            }
        }

        return new DistanceMatrix($matrix);
    }

    /**
     * @return Address[]
     */
    private function circleAddresses(int $n): array
    {
        $addresses = [];

        for ($i = 0; $i < $n; $i++) {
            $addresses[] = new Address('P' . $i, new Coordinate(0.0, 0.0));
        }

        return $addresses;
    }

    public function testSolveReturnsRequestedCountSortedByDistance(): void
    {
        $solver = new TopNRouteSolver();

        $result = $solver->solve($this->addresses(), $this->lineMatrix(), 3, new SolveOptions());

        self::assertCount(3, $result->routes);
        self::assertSame(0, $result->shortestIndex);
        self::assertSame(3, $result->requestedCount);

        [$first, $second, $third] = $result->routes;

        self::assertSame(3.0, $first->totalDistanceKm);
        self::assertSame(4.0, $second->totalDistanceKm);
        self::assertSame(4.0, $third->totalDistanceKm);

        self::assertTrue($first->isShortest);
        self::assertFalse($second->isShortest);
        self::assertFalse($third->isShortest);

        self::assertSame(1, $first->rank);
        self::assertSame(2, $second->rank);
        self::assertSame(3, $third->rank);

        self::assertSame(0.0, $first->deltaFromBestKm);
        self::assertSame(1.0, $second->deltaFromBestKm);
        self::assertSame(1.0, $third->deltaFromBestKm);

        self::assertNotSame($second->stops, $third->stops);
    }

    public function testSolveBestRouteHasCorrectCumulativeDistances(): void
    {
        $solver = new TopNRouteSolver();

        $result = $solver->solve($this->addresses(), $this->lineMatrix(), 3, new SolveOptions());
        $best = $result->routes[0];

        self::assertSame(4, $best->totalStops());

        $cumulative = array_map(
            static fn ($stop) => $stop->toArray()['cumulativeDistanceKm'],
            $best->stops
        );

        self::assertSame([0.0, 1.0, 2.0, 3.0], $cumulative);
        self::assertTrue($best->stops[0]->isStart);
        self::assertTrue($best->stops[3]->isEnd);
    }

    public function testSolveWithReturnToStartAddsClosingLeg(): void
    {
        $solver = new TopNRouteSolver();

        $result = $solver->solve(
            $this->addresses(),
            $this->lineMatrix(),
            1,
            new SolveOptions(returnToStart: true, depotIndex: 0)
        );

        $best = $result->routes[0];

        self::assertSame(6.0, $best->totalDistanceKm);
        self::assertSame(5, $best->totalStops()); // n + 1 (return to start)

        $lastStop = $best->stops[\count($best->stops) - 1];
        self::assertTrue($lastStop->isEnd);
        self::assertSame('A', $lastStop->address->label);
    }

    public function testSolveWithDepotPinsTheStartingStop(): void
    {
        $solver = new TopNRouteSolver();

        $result = $solver->solve(
            $this->addresses(),
            $this->lineMatrix(),
            3,
            new SolveOptions(depotIndex: 2)
        );

        $best = $result->routes[0];

        self::assertSame('C', $best->stops[0]->address->label);
        self::assertSame(4.0, $best->totalDistanceKm);
        self::assertSame(5.0, $result->routes[1]->totalDistanceKm);
        self::assertSame(5.0, $result->routes[2]->totalDistanceKm);
    }

    /**
     * `n = 16` is above `HELD_KARP_LIMIT` (15), so `collectCandidates()`
     * takes the heuristic-only path: one nearest-neighbor tour from the
     * fixed depot, plus 5 random-shuffle restarts, each refined by 2-opt
     * then Or-opt ({@see TopNRouteSolver::refine()}). This is the first
     * test to exercise that path (previously only n <= 10 was covered),
     * and in particular exercises Or-opt's closed-loop
     * (`returnToStart`) wraparound via `relocationDelta()`.
     *
     * For points evenly spaced on a circle, the *unique* 2-opt-optimal
     * closed tour is the angular (convex-hull) order — any other order
     * has a crossing edge that 2-opt would remove. So regardless of which
     * of the 6 candidates the random restarts produce, refinement must
     * converge to that same tour, making the result deterministic.
     */
    public function testSolveForLargeNWithReturnToStartFindsConvexHullOrder(): void
    {
        $solver = new TopNRouteSolver();
        $n = 16;

        $result = $solver->solve(
            $this->circleAddresses($n),
            $this->circleMatrix($n),
            1,
            new SolveOptions(returnToStart: true, depotIndex: 0)
        );

        $best = $result->routes[0];

        // Total length of the closed tour visiting n points evenly spaced
        // on a unit circle in angular order: n edges, each subtending
        // 2*pi/n radians, chord length 2*sin(pi/n).
        $expectedOptimal = $n * 2 * sin(M_PI / $n);

        self::assertEqualsWithDelta($expectedOptimal, $best->totalDistanceKm, 1e-9);
        self::assertCount($n + 1, $best->stops); // n stops + closing return-to-depot stop

        $visited = array_map(
            static fn ($stop) => (int) substr($stop->address->label, 1),
            \array_slice($best->stops, 0, $n)
        );
        sort($visited);
        self::assertSame(range(0, $n - 1), $visited, 'every stop is visited exactly once');

        self::assertSame('P0', $best->stops[0]->address->label, 'fixed depot stays first');
        self::assertTrue($best->stops[0]->isStart);
        $closingStop = $best->stops[$n];
        self::assertTrue($closingStop->isEnd);
        self::assertSame('P0', $closingStop->address->label, 'closing leg returns to the depot');
    }

    /**
     * Same heuristic path as above (`n = 16 > HELD_KARP_LIMIT`), but with a
     * free start and an open path (`returnToStart: false`,
     * `depotIndex: null`): `collectCandidates()` builds one
     * nearest-neighbor tour *per starting stop* (16 of them) plus 5 random
     * restarts. Exercises Or-opt's open-path (non-wraparound) branch of
     * `relocationDelta()`/`stopAfter()` for the n > 10 path.
     *
     * For points evenly spaced on a circle, the optimal *open* path drops
     * exactly one of the n equal-length edges from the closed tour, so its
     * length is (n - 1) times the common edge length, regardless of where
     * it starts/ends.
     */
    public function testSolveForLargeNWithFreeStartFindsOptimalOpenPath(): void
    {
        $solver = new TopNRouteSolver();
        $n = 16;

        $result = $solver->solve(
            $this->circleAddresses($n),
            $this->circleMatrix($n),
            1,
            new SolveOptions()
        );

        $best = $result->routes[0];
        $expectedOptimal = ($n - 1) * 2 * sin(M_PI / $n);

        self::assertEqualsWithDelta($expectedOptimal, $best->totalDistanceKm, 1e-9);
        self::assertCount($n, $best->stops); // open path: no closing stop

        $visited = array_map(
            static fn ($stop) => (int) substr($stop->address->label, 1),
            $best->stops
        );
        sort($visited);
        self::assertSame(range(0, $n - 1), $visited, 'every stop is visited exactly once');

        self::assertTrue($best->stops[0]->isStart);
        self::assertTrue($best->stops[$n - 1]->isEnd);
    }

    /**
     * Full-pipeline regression for the Held-Karp range (9 < n <= 15) with
     * the API-default shape (free start, open path): 12 stops on a
     * straight line with **index 0 in the middle**. The true optimum is
     * the end-to-end sweep (11.0); a Held-Karp candidate pinned to start
     * at index 0 could contribute no better than 16.0. The exact result
     * must come out of the pipeline regardless of what the random-restart
     * heuristics produce.
     */
    public function testSolveInHeldKarpRangeFindsExactOptimumForFreeStartOpenPath(): void
    {
        $positions = [6, 0, 1, 2, 3, 4, 5, 7, 8, 9, 10, 11]; // index => position on the line
        $n = count($positions);
        $distances = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $distances[$i][$j] = (float) abs($positions[$i] - $positions[$j]);
            }
        }

        $solver = new TopNRouteSolver();

        $result = $solver->solve(
            $this->circleAddresses($n),
            new DistanceMatrix($distances),
            1,
            new SolveOptions()
        );

        self::assertEqualsWithDelta(11.0, $result->routes[0]->totalDistanceKm, 1e-9);
    }

    /**
     * Asymmetric variant of {@see self::lineMatrix()}: moving "backward"
     * (from a higher index to a lower one) costs an extra 0.5 km per unit,
     * like one-way streets forcing a detour. dist(i,j) != dist(j,i) for
     * every pair.
     */
    private function asymmetricLineMatrix(): DistanceMatrix
    {
        return new DistanceMatrix([
            [0.0, 1.0, 2.0, 3.0],
            [1.5, 0.0, 1.0, 2.0],
            [2.5, 1.5, 0.0, 1.0],
            [3.5, 2.5, 1.5, 0.0],
        ]);
    }

    /**
     * With directed (asymmetric) distances, a tour and its reversal are
     * different routes with different lengths and must NOT be deduplicated:
     * all 4! = 24 visiting orders are distinct (vs. 24/2 = 12 for the
     * symmetric line matrix), the best is the "forward" order, and its
     * reversal appears separately with its own, longer total.
     */
    public function testSolveKeepsBothDirectionsDistinctOnAsymmetricMatrix(): void
    {
        $solver = new TopNRouteSolver();

        $result = $solver->solve($this->addresses(), $this->asymmetricLineMatrix(), 24, new SolveOptions());

        self::assertCount(24, $result->routes);

        $byOrder = [];
        foreach ($result->routes as $route) {
            $order = implode('', array_map(static fn ($stop) => $stop->address->label, $route->stops));
            $byOrder[$order] = $route->totalDistanceKm;
        }

        self::assertEqualsWithDelta(3.0, $byOrder['ABCD'], 1e-9, 'forward direction uses forward edge costs');
        self::assertEqualsWithDelta(4.5, $byOrder['DCBA'], 1e-9, 'reverse direction uses its own (more expensive) directed edges');
        self::assertEqualsWithDelta(3.0, $result->routes[0]->totalDistanceKm, 1e-9, 'true directed optimum ranks first');

        // Sanity check against the symmetric equivalent: reversals collapse.
        $symmetric = $solver->solve($this->addresses(), $this->lineMatrix(), 24, new SolveOptions());
        self::assertCount(12, $symmetric->routes);
    }

    /**
     * Regression test for the asymmetric-dedupe distance mismatch: the
     * reported totalDistanceKm of every returned route must equal the sum
     * of that route's own (directed) leg distances — the total may not be
     * carried over from a differently-oriented duplicate.
     */
    public function testSolveRouteTotalsMatchTheirLegsOnAsymmetricMatrix(): void
    {
        $solver = new TopNRouteSolver();

        foreach ([new SolveOptions(), new SolveOptions(returnToStart: true)] as $options) {
            $result = $solver->solve($this->addresses(), $this->asymmetricLineMatrix(), 24, $options);

            foreach ($result->routes as $route) {
                $legSum = array_sum(array_map(static fn ($leg) => $leg->distanceKm, $route->legs));
                self::assertEqualsWithDelta(
                    $route->totalDistanceKm,
                    $legSum,
                    1e-9,
                    'route total must equal the sum of its own directed legs'
                );
            }
        }
    }
}
