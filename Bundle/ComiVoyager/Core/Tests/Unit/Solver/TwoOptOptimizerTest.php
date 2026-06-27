<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\TourMath;
use Genaker\Bundle\ComiVoyager\Core\Solver\TwoOptOptimizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Solver\TwoOptOptimizer
 */
final class TwoOptOptimizerTest extends TestCase
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

    public function testOptimizeFixesOpenPathToSortedOrder(): void
    {
        $optimizer = new TwoOptOptimizer();
        $matrix = $this->lineMatrix();
        $options = new SolveOptions();

        $result = $optimizer->optimize($matrix, [0, 3, 1, 2], $options);

        self::assertSame([0, 1, 2, 3], $result);
        self::assertSame(3.0, TourMath::distance($matrix, $result, $options->returnToStart));
    }

    public function testOptimizeFixesClosedLoopToSortedOrder(): void
    {
        $optimizer = new TwoOptOptimizer();
        $matrix = $this->lineMatrix();
        $options = new SolveOptions(returnToStart: true);

        $result = $optimizer->optimize($matrix, [0, 2, 1, 3], $options);

        self::assertSame([0, 1, 2, 3], $result);
        self::assertSame(6.0, TourMath::distance($matrix, $result, $options->returnToStart));
    }

    public function testOptimizeNeverMovesFirstStop(): void
    {
        $optimizer = new TwoOptOptimizer();
        $matrix = $this->lineMatrix();

        $result = $optimizer->optimize($matrix, [2, 0, 3, 1], new SolveOptions());

        self::assertSame(2, $result[0]);
    }

    /**
     * Asymmetric (road-network-style) matrix: a boundary-edges-only delta
     * says reversing [1..2] of [0,1,2,3] saves 8 km — but the segment's
     * internal edge costs 0.1 km forward (1->2) and 10 km backward (2->1),
     * so the reversal actually *lengthens* the tour from 10.1 to 12 km.
     * The optimizer must account for the directed internal edges and
     * reject the move.
     */
    public function testOptimizeRejectsReversalThatOnlyLooksShorterUnderSymmetry(): void
    {
        $optimizer = new TwoOptOptimizer();
        $matrix = new DistanceMatrix([
            [0.0, 5.0, 1.0, 50.0],
            [9.0, 0.0, 0.1, 1.0],
            [1.0, 10.0, 0.0, 5.0],
            [50.0, 9.0, 50.0, 0.0],
        ]);
        $options = new SolveOptions();

        $result = $optimizer->optimize($matrix, [0, 1, 2, 3], $options);

        self::assertSame([0, 1, 2, 3], $result);
        self::assertEqualsWithDelta(10.1, TourMath::distance($matrix, $result, $options->returnToStart), 1e-9);
    }

    /**
     * The mirror case: the boundary-edges-only delta says reversing [1..2]
     * of [0,1,2,3] *costs* 2 km, but the internal edge is 10 km forward
     * (1->2) vs 0.1 km backward (2->1), so the reversal actually shortens
     * the tour from 12 to 4.1 km. The optimizer must find and apply it.
     */
    public function testOptimizeAppliesReversalThatOnlyHelpsUnderAsymmetry(): void
    {
        $optimizer = new TwoOptOptimizer();
        $matrix = new DistanceMatrix([
            [0.0, 1.0, 2.0, 100.0],
            [1.0, 0.0, 10.0, 2.0],
            [2.0, 0.1, 0.0, 1.0],
            [100.0, 2.0, 1.0, 0.0],
        ]);
        $options = new SolveOptions();

        $result = $optimizer->optimize($matrix, [0, 1, 2, 3], $options);

        self::assertSame([0, 2, 1, 3], $result);
        self::assertEqualsWithDelta(4.1, TourMath::distance($matrix, $result, $options->returnToStart), 1e-9);
    }

    /**
     * Closed-loop variant of the asymmetric "reject" case: the boundary
     * delta for reversing [1..2] of the loop [0,1,2,3] says -8 km, but the
     * internal edge costs 0.1 km forward vs 10 km backward, so the true
     * directed delta is +1.9 km — the loop must stay unchanged. Exercises
     * the wraparound (`($j + 1) % $size`) boundary branch together with
     * the internal-edge term.
     */
    public function testOptimizeRejectsLoopReversalThatOnlyLooksShorterUnderSymmetry(): void
    {
        $optimizer = new TwoOptOptimizer();
        $matrix = new DistanceMatrix([
            [0.0, 5.0, 1.0, 50.0],
            [50.0, 0.0, 0.1, 1.0],
            [50.0, 10.0, 0.0, 5.0],
            [1.0, 50.0, 50.0, 0.0],
        ]);
        $options = new SolveOptions(returnToStart: true);

        $result = $optimizer->optimize($matrix, [0, 1, 2, 3], $options);

        self::assertSame([0, 1, 2, 3], $result);
        self::assertEqualsWithDelta(11.1, TourMath::distance($matrix, $result, $options->returnToStart), 1e-9);
    }

    /**
     * Directed "cheap clockwise / expensive counterclockwise" cycle: the
     * optimizer must escape a shuffled closed loop into the cheap directed
     * cycle, which requires the asymmetric delta to price both boundary
     * and internal edges in their actual travel direction.
     */
    public function testOptimizeFindsCheapDirectionOfDirectedCycle(): void
    {
        $optimizer = new TwoOptOptimizer();
        // d(i, i+1 mod 4) = 1 (clockwise), d(i+1, i) = 10 (counterclockwise),
        // diagonals 5 both ways.
        $matrix = new DistanceMatrix([
            [0.0, 1.0, 5.0, 10.0],
            [10.0, 0.0, 1.0, 5.0],
            [5.0, 10.0, 0.0, 1.0],
            [1.0, 5.0, 10.0, 0.0],
        ]);
        $options = new SolveOptions(returnToStart: true);

        $result = $optimizer->optimize($matrix, [0, 2, 1, 3], $options);

        self::assertSame([0, 1, 2, 3], $result);
        self::assertEqualsWithDelta(4.0, TourMath::distance($matrix, $result, $options->returnToStart), 1e-9);
    }

    /**
     * Differential test on a genuinely directed matrix (dist(i,j) !=
     * dist(j,i) for every pair, no coincidental ties): for every segment
     * `(i, j)` and both open/closed shapes, the optimizer's combined delta
     * (boundary `reversalDelta` + `internalReversalDelta`) must exactly
     * match the brute-force difference between {@see TourMath::distance()}
     * of the reversed and original tours.
     */
    public function testReversalDeltaMatchesBruteForceOnDirectedMatrix(): void
    {
        $optimizer = new TwoOptOptimizer();
        $size = 7;
        $distances = [];

        for ($from = 0; $from < $size; $from++) {
            for ($to = 0; $to < $size; $to++) {
                // abs() term breaks ties, from/to terms break symmetry.
                $distances[$from][$to] = $from === $to ? 0.0 : abs($from - $to) + $from * 0.013 + $to * 0.029;
            }
        }

        $matrix = new DistanceMatrix($distances);
        self::assertFalse($matrix->isSymmetric());

        $tour = [0, 3, 1, 5, 2, 6, 4];

        $boundaryDelta = new \ReflectionMethod(TwoOptOptimizer::class, 'reversalDelta');
        $internalDelta = new \ReflectionMethod(TwoOptOptimizer::class, 'internalReversalDelta');
        $reverseSegment = new \ReflectionMethod(TwoOptOptimizer::class, 'reverseSegment');

        foreach ([false, true] as $returnToStart) {
            for ($i = 1; $i < $size - 1; $i++) {
                for ($j = $i + 1; $j < $size; $j++) {
                    $delta = $boundaryDelta->invoke($optimizer, $matrix, $tour, $i, $j, $returnToStart)
                        + $internalDelta->invoke($optimizer, $matrix, $tour, $i, $j);

                    $reversed = $reverseSegment->invoke($optimizer, $tour, $i, $j);
                    $expectedDelta = TourMath::distance($matrix, $reversed, $returnToStart)
                        - TourMath::distance($matrix, $tour, $returnToStart);

                    self::assertEqualsWithDelta($expectedDelta, $delta, 1e-9, sprintf(
                        'mismatch for i=%d j=%d returnToStart=%s',
                        $i,
                        $j,
                        $returnToStart ? 'true' : 'false'
                    ));
                }
            }
        }
    }
}
