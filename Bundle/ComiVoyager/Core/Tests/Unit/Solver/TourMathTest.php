<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\TourMath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Solver\TourMath
 */
final class TourMathTest extends TestCase
{
    /**
     * Directed matrix: forward (i < j) legs cost |i - j|, backward legs
     * cost an extra 0.5 km — dist(i,j) != dist(j,i) for every pair.
     */
    private function directedMatrix(): DistanceMatrix
    {
        return new DistanceMatrix([
            [0.0, 1.0, 2.0, 3.0],
            [1.5, 0.0, 1.0, 2.0],
            [2.5, 1.5, 0.0, 1.0],
            [3.5, 2.5, 1.5, 0.0],
        ]);
    }

    public function testDistanceUsesDirectedEdges(): void
    {
        $matrix = $this->directedMatrix();

        self::assertEqualsWithDelta(3.0, TourMath::distance($matrix, [0, 1, 2, 3], false), 1e-9);
        self::assertEqualsWithDelta(4.5, TourMath::distance($matrix, [3, 2, 1, 0], false), 1e-9);
        // Closed loop adds the directed closing leg 3 -> 0 (3.5).
        self::assertEqualsWithDelta(6.5, TourMath::distance($matrix, [0, 1, 2, 3], true), 1e-9);
    }

    public function testNormalizeOpenFreeStartCollapsesReversalOnlyWhenSymmetric(): void
    {
        $options = new SolveOptions();

        // Symmetric (default): forward and reverse are the same route ->
        // both normalize to the lexicographically smaller representation.
        self::assertSame([0, 1, 2, 3], TourMath::normalize([0, 1, 2, 3], $options));
        self::assertSame([0, 1, 2, 3], TourMath::normalize([3, 2, 1, 0], $options));

        // Asymmetric: the two directions are different routes -> each keeps
        // its own identity (and therefore its own dedupe key).
        self::assertSame([0, 1, 2, 3], TourMath::normalize([0, 1, 2, 3], $options, false));
        self::assertSame([3, 2, 1, 0], TourMath::normalize([3, 2, 1, 0], $options, false));
        self::assertNotSame(
            TourMath::key(TourMath::normalize([0, 1, 2, 3], $options, false)),
            TourMath::key(TourMath::normalize([3, 2, 1, 0], $options, false))
        );
    }

    public function testNormalizeClosedLoopFreeStartAlwaysCollapsesRotations(): void
    {
        $options = new SolveOptions(returnToStart: true);

        // Rotations of a loop traverse identical directed edges, so they
        // collapse regardless of symmetry.
        foreach ([true, false] as $symmetric) {
            self::assertSame(
                TourMath::normalize([0, 1, 2, 3], $options, $symmetric),
                TourMath::normalize([2, 3, 0, 1], $options, $symmetric),
                'rotated loop must normalize identically (symmetric=' . var_export($symmetric, true) . ')'
            );
        }
    }

    public function testNormalizeClosedLoopFreeStartCollapsesReversalOnlyWhenSymmetric(): void
    {
        $options = new SolveOptions(returnToStart: true);

        // Symmetric: a loop and its reversal are the same route.
        self::assertSame(
            TourMath::normalize([0, 1, 2, 3], $options),
            TourMath::normalize([0, 3, 2, 1], $options)
        );

        // Asymmetric: opposite directions use different directed edges.
        self::assertNotSame(
            TourMath::normalize([0, 1, 2, 3], $options, false),
            TourMath::normalize([0, 3, 2, 1], $options, false)
        );
    }

    public function testNormalizeClosedLoopWithDepotCollapsesReversalOnlyWhenSymmetric(): void
    {
        $options = new SolveOptions(returnToStart: true, depotIndex: 0);

        // Symmetric: same loop walked the other way around from the same
        // depot is the same route.
        self::assertSame(
            TourMath::normalize([0, 1, 2, 3], $options),
            TourMath::normalize([0, 3, 2, 1], $options)
        );

        // Asymmetric: kept distinct.
        self::assertSame([0, 1, 2, 3], TourMath::normalize([0, 1, 2, 3], $options, false));
        self::assertSame([0, 3, 2, 1], TourMath::normalize([0, 3, 2, 1], $options, false));
    }

    public function testNormalizeOpenPathWithDepotIsUnchangedRegardlessOfSymmetry(): void
    {
        $options = new SolveOptions(depotIndex: 0);

        foreach ([true, false] as $symmetric) {
            self::assertSame([0, 2, 1, 3], TourMath::normalize([0, 2, 1, 3], $options, $symmetric));
        }
    }

    public function testKeyIsStableAndDistinct(): void
    {
        self::assertSame('0,2,1,3', TourMath::key([0, 2, 1, 3]));
        self::assertNotSame(TourMath::key([0, 1, 2, 3]), TourMath::key([0, 1, 3, 2]));
    }
}
