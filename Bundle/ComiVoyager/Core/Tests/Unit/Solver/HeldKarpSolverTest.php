<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\HeldKarpSolver;
use Genaker\Bundle\ComiVoyager\Core\Solver\PermutationSolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Solver\HeldKarpSolver
 */
final class HeldKarpSolverTest extends TestCase
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

    public function testSolveSingleStopReturnsZeroDistance(): void
    {
        $solver = new HeldKarpSolver();
        $matrix = new DistanceMatrix([[0.0]]);

        $result = $solver->solve($matrix, new SolveOptions());

        self::assertSame(['tour' => [0], 'totalDistanceKm' => 0.0], $result);
    }

    public function testSolveOpenPathWithFreeStartFindsOptimalSweep(): void
    {
        $solver = new HeldKarpSolver();

        $result = $solver->solve($this->lineMatrix(), new SolveOptions());

        // Either end-to-end sweep is the true free-start optimum (3.0).
        self::assertContains($result['tour'], [[0, 1, 2, 3], [3, 2, 1, 0]]);
        self::assertSame(3.0, $result['totalDistanceKm']);
    }

    /**
     * Regression: stops on a straight line, but **index 0 sits in the
     * middle** of it. The true free-start open path sweeps end to end
     * (7.0); the old behavior pinned the start to index 0 and could do no
     * better than 10.0 (start in the middle, run to the near end, then
     * backtrack past the start to the far end). The virtual-depot solve
     * must pick the optimal endpoints itself.
     */
    public function testSolveOpenPathWithFreeStartDoesNotPinTheStartToIndexZero(): void
    {
        $positions = [4, 0, 1, 2, 3, 5, 6, 7]; // index => position on the line
        $size = count($positions);
        $distances = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $distances[$i][$j] = (float) abs($positions[$i] - $positions[$j]);
            }
        }

        $result = (new HeldKarpSolver())->solve(new DistanceMatrix($distances), new SolveOptions());

        self::assertSame(7.0, $result['totalDistanceKm']);
        // The optimal path must start at one end of the line, not at index 0.
        self::assertContains($positions[$result['tour'][0]], [0, 7]);
    }

    /**
     * Genuinely directed matrix — dist(i,j) != dist(j,i) for every pair,
     * no coincidental ties.
     */
    private function directedMatrix(int $size): DistanceMatrix
    {
        $distances = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $distances[$i][$j] = $i === $j ? 0.0 : abs($i - $j) + $i * 0.013 + $j * 0.029;
            }
        }

        $matrix = new DistanceMatrix($distances);
        self::assertFalse($matrix->isSymmetric());

        return $matrix;
    }

    private function exhaustiveBestTotal(DistanceMatrix $matrix, SolveOptions $options): float
    {
        return min(array_map(
            static fn (array $result): float => $result['totalDistanceKm'],
            (new PermutationSolver())->solve($matrix, $options)
        ));
    }

    /**
     * Differential test against exhaustive search on a genuinely directed
     * (asymmetric) matrix: for a free-start open path, Held-Karp must
     * match the best total found by enumerating every permutation.
     */
    public function testSolveFreeStartOpenPathMatchesExhaustiveSearchOnDirectedMatrix(): void
    {
        $matrix = $this->directedMatrix(7);
        $options = new SolveOptions();

        $heldKarp = (new HeldKarpSolver())->solve($matrix, $options);

        self::assertEqualsWithDelta($this->exhaustiveBestTotal($matrix, $options), $heldKarp['totalDistanceKm'], 1e-9);
    }

    /**
     * The other three request shapes must also match exhaustive search on
     * a directed matrix — they bypass the virtual depot (closed loops are
     * rotation-invariant; a fixed depot pins the start explicitly), and
     * this guards the dispatch in solve() from routing them wrongly.
     *
     * @dataProvider nonFreeOpenShapeProvider
     */
    public function testSolveMatchesExhaustiveSearchOnDirectedMatrixForOtherShapes(SolveOptions $options): void
    {
        $matrix = $this->directedMatrix(7);

        $heldKarp = (new HeldKarpSolver())->solve($matrix, $options);

        self::assertEqualsWithDelta($this->exhaustiveBestTotal($matrix, $options), $heldKarp['totalDistanceKm'], 1e-9);

        if ($options->depotIndex !== null) {
            self::assertSame($options->depotIndex, $heldKarp['tour'][0], 'fixed depot must stay first');
        }
    }

    /**
     * @return iterable<string, array{SolveOptions}>
     */
    public static function nonFreeOpenShapeProvider(): iterable
    {
        yield 'free-start closed loop' => [new SolveOptions(returnToStart: true)];
        yield 'fixed-depot open path' => [new SolveOptions(depotIndex: 3)];
        yield 'fixed-depot closed loop' => [new SolveOptions(returnToStart: true, depotIndex: 3)];
    }

    /**
     * Randomized differential test: free-start open paths on several
     * seeded random directed matrices must always match exhaustive search.
     */
    public function testSolveFreeStartOpenPathMatchesExhaustiveSearchOnRandomDirectedMatrices(): void
    {
        $options = new SolveOptions();

        foreach ([7, 42, 1337] as $seed) {
            mt_srand($seed);
            $size = 6;
            $distances = [];

            for ($i = 0; $i < $size; $i++) {
                for ($j = 0; $j < $size; $j++) {
                    $distances[$i][$j] = $i === $j ? 0.0 : mt_rand(1, 1000) / 10;
                }
            }

            $matrix = new DistanceMatrix($distances);

            $heldKarp = (new HeldKarpSolver())->solve($matrix, $options);

            self::assertEqualsWithDelta(
                $this->exhaustiveBestTotal($matrix, $options),
                $heldKarp['totalDistanceKm'],
                1e-9,
                "suboptimal free-start open path for seed {$seed}"
            );
        }
    }

    /**
     * Two stops with directed distances: the free-start open path must
     * travel in the cheaper direction (1 -> 0 at 1.0, not 0 -> 1 at 5.0) —
     * the smallest case where the virtual depot's endpoint choice matters.
     */
    public function testSolveTwoStopFreeStartPicksTheCheaperDirection(): void
    {
        $matrix = new DistanceMatrix([
            [0.0, 5.0],
            [1.0, 0.0],
        ]);

        $result = (new HeldKarpSolver())->solve($matrix, new SolveOptions());

        self::assertSame([1, 0], $result['tour']);
        self::assertSame(1.0, $result['totalDistanceKm']);
    }

    /**
     * The virtual depot must never leak into the result: the returned tour
     * is exactly a permutation of the real stop indices.
     */
    public function testSolveFreeStartOpenPathTourContainsExactlyTheRealStops(): void
    {
        $size = 6;
        $result = (new HeldKarpSolver())->solve($this->directedMatrix($size), new SolveOptions());

        $tour = $result['tour'];
        sort($tour);
        self::assertSame(range(0, $size - 1), $tour);
    }

    public function testSolveReturnToStartFindsOptimalClosedLoop(): void
    {
        $solver = new HeldKarpSolver();

        $result = $solver->solve($this->lineMatrix(), new SolveOptions(returnToStart: true));

        self::assertSame(0, $result['tour'][0]);
        self::assertSame(6.0, $result['totalDistanceKm']);
    }

    public function testSolveMatchesPermutationSolverForSharedStart(): void
    {
        $matrix = $this->lineMatrix();
        $options = new SolveOptions(depotIndex: 0);

        $heldKarp = (new HeldKarpSolver())->solve($matrix, $options);

        $permutationResults = (new PermutationSolver())->solve($matrix, $options);
        $bestPermutationTotal = min(array_map(
            static fn (array $result): float => $result['totalDistanceKm'],
            $permutationResults
        ));

        self::assertSame($bestPermutationTotal, $heldKarp['totalDistanceKm']);
    }
}
