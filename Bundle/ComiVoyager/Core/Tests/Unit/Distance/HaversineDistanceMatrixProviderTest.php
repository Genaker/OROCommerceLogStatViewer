<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Distance;

use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider
 */
final class HaversineDistanceMatrixProviderTest extends TestCase
{
    public function testGetNameReturnsHaversine(): void
    {
        self::assertSame('haversine', (new HaversineDistanceMatrixProvider())->getName());
    }

    public function testBuildReturnsZeroDiagonal(): void
    {
        $provider = new HaversineDistanceMatrixProvider();
        $matrix = $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
        ]);

        self::assertSame(2, $matrix->size());
        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
        self::assertSame(0.0, $matrix->distanceBetween(1, 1));
    }

    public function testBuildIsSymmetric(): void
    {
        $provider = new HaversineDistanceMatrixProvider();
        $matrix = $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
        ]);

        self::assertSame($matrix->distanceBetween(0, 1), $matrix->distanceBetween(1, 0));
    }

    public function testLondonToParisDistanceIsApproximatelyKnownValue(): void
    {
        $provider = new HaversineDistanceMatrixProvider();
        $matrix = $provider->build([
            new Coordinate(51.5074, -0.1278), // London
            new Coordinate(48.8566, 2.3522),  // Paris
        ]);

        // Great-circle distance between London and Paris is ~343.5km.
        self::assertEqualsWithDelta(343.5, $matrix->distanceBetween(0, 1), 2.0);
    }
}
