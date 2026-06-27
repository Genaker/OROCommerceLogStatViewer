<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\PermutationSolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Solver\PermutationSolver
 */
final class PermutationSolverTest extends TestCase
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

    public function testSolveReturnsAllPermutationsForFreeStart(): void
    {
        $solver = new PermutationSolver();

        $results = $solver->solve($this->lineMatrix(), new SolveOptions());

        self::assertCount(24, $results); // 4!

        $totals = array_map(static fn (array $result): float => $result['totalDistanceKm'], $results);
        self::assertSame(3.0, min($totals));
    }

    public function testSolveFindsOptimalSortedTour(): void
    {
        $solver = new PermutationSolver();

        $results = $solver->solve($this->lineMatrix(), new SolveOptions());

        $best = array_filter($results, static fn (array $result): bool => $result['totalDistanceKm'] === 3.0);

        self::assertNotEmpty($best);

        foreach ($best as $result) {
            self::assertContains($result['tour'], [[0, 1, 2, 3], [3, 2, 1, 0]]);
        }
    }

    public function testSolveWithDepotFiltersToToursStartingAtDepot(): void
    {
        $solver = new PermutationSolver();

        $results = $solver->solve($this->lineMatrix(), new SolveOptions(depotIndex: 0));

        self::assertCount(6, $results); // 3!

        foreach ($results as $result) {
            self::assertSame(0, $result['tour'][0]);
        }

        $totals = array_map(static fn (array $result): float => $result['totalDistanceKm'], $results);
        self::assertSame(3.0, min($totals));
    }

    public function testSolveWithDepotAndReturnToStartFindsClosedLoopOptimum(): void
    {
        $solver = new PermutationSolver();

        $results = $solver->solve($this->lineMatrix(), new SolveOptions(returnToStart: true, depotIndex: 0));

        $totals = array_map(static fn (array $result): float => $result['totalDistanceKm'], $results);

        // Closed loop over points on a line always costs 2 * range.
        self::assertSame(6.0, min($totals));
    }
}
