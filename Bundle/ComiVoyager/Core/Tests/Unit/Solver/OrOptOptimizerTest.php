<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\OrOptOptimizer;
use Genaker\Bundle\ComiVoyager\Core\Solver\TourMath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Solver\OrOptOptimizer
 */
final class OrOptOptimizerTest extends TestCase
{
    /**
     * Six points on a line at positions 0..5 -> distance(i, j) = |i - j|.
     */
    private function lineMatrix(): DistanceMatrix
    {
        $size = 6;
        $matrix = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $matrix[$i][$j] = (float) abs($i - $j);
            }
        }

        return new DistanceMatrix($matrix);
    }

    /**
     * Seven points whose pairwise distances are all distinct (no two pairs
     * share the same value) but still symmetric, so a differential test
     * comparing {@see OrOptOptimizer::relocationDelta()} against a
     * brute-force recomputation can't accidentally pass due to
     * coincidental ties.
     */
    private function distinctDistancesMatrix(): DistanceMatrix
    {
        $size = 7;
        $matrix = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $matrix[$i][$j] = $i === $j ? 0.0 : abs($i - $j) + ($i + $j) * 0.01;
            }
        }

        return new DistanceMatrix($matrix);
    }

    /**
     * Genuinely directed matrix — dist(i,j) != dist(j,i) for every pair
     * (the from/to terms differ), as a road network with one-way streets
     * would produce.
     */
    private function directedMatrix(): DistanceMatrix
    {
        $size = 7;
        $matrix = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $matrix[$i][$j] = $i === $j ? 0.0 : abs($i - $j) + $i * 0.013 + $j * 0.029;
            }
        }

        return new DistanceMatrix($matrix);
    }

    public function testOptimizeRelocatesMisplacedSegmentToSortedOrder(): void
    {
        $optimizer = new OrOptOptimizer();
        $matrix = $this->lineMatrix();
        $options = new SolveOptions();

        // Stops 4 and 5 were inserted right after 1, splitting the optimal
        // run of 2..5 into two pieces; relocating that 2-stop segment to the
        // end restores the sorted (optimal) order.
        $result = $optimizer->optimize($matrix, [0, 1, 4, 5, 2, 3], $options);

        self::assertSame([0, 1, 2, 3, 4, 5], $result);
        self::assertSame(5.0, TourMath::distance($matrix, $result, $options->returnToStart));
    }

    public function testOptimizeNeverMovesFirstStop(): void
    {
        $optimizer = new OrOptOptimizer();
        $matrix = $this->lineMatrix();

        $result = $optimizer->optimize($matrix, [3, 1, 4, 5, 2, 0], new SolveOptions());

        self::assertSame(3, $result[0]);
    }

    public function testOptimizeDoesNotWorsenAnAlreadyOptimalTour(): void
    {
        $optimizer = new OrOptOptimizer();
        $matrix = $this->lineMatrix();
        $options = new SolveOptions();

        $result = $optimizer->optimize($matrix, [0, 1, 2, 3, 4, 5], $options);

        self::assertSame([0, 1, 2, 3, 4, 5], $result);
    }

    public function testOptimizeRelocatesSegmentInClosedLoop(): void
    {
        $optimizer = new OrOptOptimizer();
        $matrix = $this->lineMatrix();
        $options = new SolveOptions(returnToStart: true);

        $result = $optimizer->optimize($matrix, [0, 1, 4, 5, 2, 3], $options);

        $resultDistance = TourMath::distance($matrix, $result, $options->returnToStart);
        $originalDistance = TourMath::distance($matrix, [0, 1, 4, 5, 2, 3], $options->returnToStart);

        self::assertSame(0, $result[0]);
        self::assertLessThanOrEqual($originalDistance, $resultDistance);
    }

    /**
     * {@see OrOptOptimizer::relocationDelta()} computes the change in total
     * tour distance in O(1) by looking only at the edges touching the
     * segment's old and new boundaries. This test verifies that, for every
     * valid (segment, insertion point, open/closed) combination on a tour
     * with no coincidental distance ties, the O(1) delta exactly matches
     * the difference between brute-force {@see TourMath::distance()} calls
     * before and after {@see OrOptOptimizer::relocate()} — and that
     * position 0 is never moved.
     */
    public function testRelocationDeltaMatchesBruteForceRecomputation(): void
    {
        $this->assertRelocationDeltaMatchesBruteForce($this->distinctDistancesMatrix());
    }

    /**
     * Same differential check on a genuinely **directed** (asymmetric)
     * matrix: Or-opt relocations keep the segment's forward orientation,
     * so `relocationDelta()`'s directed boundary-edge lookups must remain
     * exact when dist(i,j) != dist(j,i) — the case OSRM/Google road
     * matrices produce.
     */
    public function testRelocationDeltaMatchesBruteForceOnDirectedMatrix(): void
    {
        $matrix = $this->directedMatrix();
        self::assertFalse($matrix->isSymmetric());

        $this->assertRelocationDeltaMatchesBruteForce($matrix);
    }

    /**
     * Asymmetric behavioral case: on the "backward legs cost extra" line
     * matrix, relocating the stranded stop 1 from the tail back between 0
     * and 2 must be found via directed deltas, restoring the cheap
     * forward order.
     */
    public function testOptimizeRelocatesStopUsingDirectedDistances(): void
    {
        $optimizer = new OrOptOptimizer();
        // 4 points: forward (i < j) legs cost |i - j|, backward legs +0.5.
        $matrix = new DistanceMatrix([
            [0.0, 1.0, 2.0, 3.0],
            [1.5, 0.0, 1.0, 2.0],
            [2.5, 1.5, 0.0, 1.0],
            [3.5, 2.5, 1.5, 0.0],
        ]);
        $options = new SolveOptions();

        $result = $optimizer->optimize($matrix, [0, 2, 3, 1], $options);

        self::assertSame([0, 1, 2, 3], $result);
        self::assertEqualsWithDelta(3.0, TourMath::distance($matrix, $result, $options->returnToStart), 1e-9);
    }

    private function assertRelocationDeltaMatchesBruteForce(DistanceMatrix $matrix): void
    {
        $optimizer = new OrOptOptimizer();
        $tour = [0, 3, 1, 5, 2, 6, 4];
        $size = count($tour);

        $deltaMethod = new \ReflectionMethod(OrOptOptimizer::class, 'relocationDelta');
        $relocateMethod = new \ReflectionMethod(OrOptOptimizer::class, 'relocate');

        foreach ([false, true] as $returnToStart) {
            for ($length = 1; $length <= 3; $length++) {
                for ($start = 1; $start <= $size - $length; $start++) {
                    for ($insertAfter = 0; $insertAfter < $size; $insertAfter++) {
                        if ($insertAfter >= $start - 1 && $insertAfter < $start + $length) {
                            continue;
                        }

                        $delta = $deltaMethod->invoke($optimizer, $matrix, $tour, $start, $length, $insertAfter, $returnToStart);
                        $candidate = $relocateMethod->invoke($optimizer, $tour, $start, $length, $insertAfter);

                        $expectedDelta = TourMath::distance($matrix, $candidate, $returnToStart)
                            - TourMath::distance($matrix, $tour, $returnToStart);

                        self::assertEqualsWithDelta($expectedDelta, $delta, 1e-9, sprintf(
                            'mismatch for length=%d start=%d insertAfter=%d returnToStart=%s',
                            $length,
                            $start,
                            $insertAfter,
                            $returnToStart ? 'true' : 'false'
                        ));

                        self::assertSame($tour[0], $candidate[0], 'position 0 must never move');
                    }
                }
            }
        }
    }
}
