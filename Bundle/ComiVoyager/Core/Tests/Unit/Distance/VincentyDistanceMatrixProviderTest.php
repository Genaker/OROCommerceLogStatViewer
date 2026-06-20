<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Distance;

use Genaker\Bundle\ComiVoyager\Core\Distance\VincentyDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Distance\VincentyDistanceMatrixProvider
 */
final class VincentyDistanceMatrixProviderTest extends TestCase
{
    public function testGetNameReturnsVincenty(): void
    {
        self::assertSame('vincenty', (new VincentyDistanceMatrixProvider())->getName());
    }

    public function testBuildReturnsZeroDiagonal(): void
    {
        $provider = new VincentyDistanceMatrixProvider();
        $matrix = $provider->build([
            new Coordinate(-37.95103341667, 144.42486789),
            new Coordinate(-37.65282113889, 143.92649552778),
        ]);

        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
        self::assertSame(0.0, $matrix->distanceBetween(1, 1));
    }

    public function testBuildIsSymmetric(): void
    {
        $provider = new VincentyDistanceMatrixProvider();
        $matrix = $provider->build([
            new Coordinate(-37.95103341667, 144.42486789),
            new Coordinate(-37.65282113889, 143.92649552778),
        ]);

        self::assertEqualsWithDelta($matrix->distanceBetween(0, 1), $matrix->distanceBetween(1, 0), 1e-9);
    }

    public function testFlindersPeakToBuninyongMatchesKnownGeodesicDistance(): void
    {
        $provider = new VincentyDistanceMatrixProvider();

        // Classic Vincenty (1975) inverse problem test case:
        // Flinders Peak -> Buninyong = 54972.271m, ellipsoidal azimuths 306°52'05.37", 86°25'41.62".
        $matrix = $provider->build([
            new Coordinate(-37.95103341667, 144.42486789),
            new Coordinate(-37.65282113889, 143.92649552778),
        ]);

        self::assertEqualsWithDelta(54.972271, $matrix->distanceBetween(0, 1), 0.001);
    }

    public function testLondonToParisDistanceIsApproximatelyKnownValue(): void
    {
        $provider = new VincentyDistanceMatrixProvider();
        $matrix = $provider->build([
            new Coordinate(51.5074, -0.1278), // London
            new Coordinate(48.8566, 2.3522),  // Paris
        ]);

        self::assertEqualsWithDelta(343.6, $matrix->distanceBetween(0, 1), 2.0);
    }
}
