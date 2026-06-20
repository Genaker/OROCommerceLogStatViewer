<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Model;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix
 */
final class DistanceMatrixTest extends TestCase
{
    public function testIsSymmetricForPureMathStyleMatrix(): void
    {
        $matrix = new DistanceMatrix([
            [0.0, 1.0, 2.0],
            [1.0, 0.0, 1.0],
            [2.0, 1.0, 0.0],
        ]);

        self::assertTrue($matrix->isSymmetric());
    }

    public function testIsNotSymmetricForRoadNetworkStyleMatrix(): void
    {
        // dist(0,1) != dist(1,0) — e.g. a one-way street detour.
        $matrix = new DistanceMatrix([
            [0.0, 1.0, 2.0],
            [4.5, 0.0, 1.0],
            [2.0, 1.0, 0.0],
        ]);

        self::assertFalse($matrix->isSymmetric());
    }

    public function testIsSymmetricToleratesFloatingPointNoise(): void
    {
        // Mirrored values differing by far less than the 1e-6 km (1 mm)
        // tolerance still count as symmetric.
        $matrix = new DistanceMatrix([
            [0.0, 1.0],
            [1.0 + 1e-12, 0.0],
        ]);

        self::assertTrue($matrix->isSymmetric());
    }

    public function testSizeAndDistanceBetween(): void
    {
        $matrix = new DistanceMatrix([
            [0.0, 7.5],
            [3.25, 0.0],
        ]);

        self::assertSame(2, $matrix->size());
        self::assertSame(7.5, $matrix->distanceBetween(0, 1));
        self::assertSame(3.25, $matrix->distanceBetween(1, 0));
    }
}
