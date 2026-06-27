<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Distance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Distance\PostgisDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Distance\PostgisDistanceMatrixProvider
 */
final class PostgisDistanceMatrixProviderTest extends TestCase
{
    public function testBuildIssuesASingleQueryAndMirrorsTheUpperTriangle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'CROSS JOIN')
                    && str_contains($sql, 'WHERE p1.idx < p2.idx')),
                [
                    'lng0' => -74.0060,
                    'lat0' => 40.7128,
                    'lng1' => -118.2437,
                    'lat1' => 34.0522,
                    'lng2' => -87.6298,
                    'lat2' => 41.8781,
                ]
            )
            ->willReturn([
                ['i' => 0, 'j' => 1, 'meters' => '3935746.0'],
                ['i' => 0, 'j' => 2, 'meters' => '1145539.0'],
                ['i' => 1, 'j' => 2, 'meters' => '2804544.0'],
            ]);

        $provider = new PostgisDistanceMatrixProvider($connection, new NullLogger());

        $matrix = $provider->build([
            new Coordinate(40.7128, -74.0060),
            new Coordinate(34.0522, -118.2437),
            new Coordinate(41.8781, -87.6298),
        ]);

        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
        self::assertSame(0.0, $matrix->distanceBetween(1, 1));
        self::assertSame(0.0, $matrix->distanceBetween(2, 2));
        self::assertEqualsWithDelta(3935.746, $matrix->distanceBetween(0, 1), 0.001);
        self::assertEqualsWithDelta(3935.746, $matrix->distanceBetween(1, 0), 0.001);
        self::assertEqualsWithDelta(1145.539, $matrix->distanceBetween(0, 2), 0.001);
        self::assertEqualsWithDelta(1145.539, $matrix->distanceBetween(2, 0), 0.001);
        self::assertEqualsWithDelta(2804.544, $matrix->distanceBetween(1, 2), 0.001);
        self::assertEqualsWithDelta(2804.544, $matrix->distanceBetween(2, 1), 0.001);
    }

    public function testBuildReturnsAllZeroMatrixWithoutQueryingForFewerThanTwoCoordinates(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $provider = new PostgisDistanceMatrixProvider($connection, new NullLogger());

        $matrix = $provider->build([new Coordinate(40.7128, -74.0060)]);

        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
    }

    public function testBuildWrapsDbalExceptionsAsDistanceProviderUnavailable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willThrowException(
            $this->createMock(DbalException::class)
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $provider = new PostgisDistanceMatrixProvider($connection, $logger);

        $this->expectException(DistanceProviderUnavailableException::class);

        $provider->build([
            new Coordinate(40.7128, -74.0060),
            new Coordinate(34.0522, -118.2437),
        ]);
    }

    public function testGetNameReturnsPostgis(): void
    {
        $provider = new PostgisDistanceMatrixProvider($this->createMock(Connection::class), new NullLogger());

        self::assertSame('postgis', $provider->getName());
    }
}
