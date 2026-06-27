<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Distance;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Distance\OsrmDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Distance\OsrmDistanceMatrixProvider
 */
final class OsrmDistanceMatrixProviderTest extends TestCase
{
    public function testGetNameReturnsOsrm(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $provider = new OsrmDistanceMatrixProvider(new MockHttpClient(), $configManager, new NullLogger());

        self::assertSame('osrm', $provider->getName());
    }

    public function testBuildConvertsMetersToKilometers(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'code' => 'Ok',
            'distances' => [
                [0.0, 1500.0],
                [1500.0, 0.0],
            ],
        ]));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $provider = new OsrmDistanceMatrixProvider($httpClient, $configManager, new NullLogger());

        $matrix = $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
        ]);

        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
        self::assertSame(1.5, $matrix->distanceBetween(0, 1));
        self::assertSame(1.5, $matrix->distanceBetween(1, 0));
    }

    public function testBuildThrowsWhenOsrmReturnsError(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['code' => 'NoRoute']));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $provider = new OsrmDistanceMatrixProvider($httpClient, $configManager, new NullLogger());

        $this->expectException(DistanceProviderUnavailableException::class);

        $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
        ]);
    }

    public function testBuildReturnsZeroMatrixForFewerThanTwoCoordinates(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $provider = new OsrmDistanceMatrixProvider(new MockHttpClient(), $configManager, new NullLogger());

        $matrix = $provider->build([new Coordinate(51.5074, -0.1278)]);

        self::assertSame(1, $matrix->size());
        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
    }

    /**
     * Regression: OSRM returns null for unroutable pairs (e.g. islands
     * with no ferry service). Casting null to float silently produces 0.0,
     * which makes unreachable destinations appear as free travel. This must
     * be a hard provider failure: throw DistanceProviderUnavailableException
     * instead of silently corrupting the matrix.
     */
    public function testBuildThrowsWhenOsrmReturnsNullDistance(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'code' => 'Ok',
            'distances' => [
                [0.0, null],      // unroutable pair (island)
                [null, 0.0],
            ],
        ]));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $provider = new OsrmDistanceMatrixProvider($httpClient, $configManager, new NullLogger());

        $this->expectException(DistanceProviderUnavailableException::class);
        $this->expectExceptionMessage('OSRM returned non-numeric distance for pair [0, 1]: null (unroutable)');

        $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
        ]);
    }

    /**
     * Unroutable pair detected on a symmetric distance matrix (null in both
     * directions). The error message must identify the first occurrence.
     */
    public function testBuildThrowsWhenOsrmReturnsNullDistanceInSymmetricMatrix(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'code' => 'Ok',
            'distances' => [
                [0.0, 1000.0, null],
                [1000.0, 0.0, 2000.0],
                [null, 2000.0, 0.0],
            ],
        ]));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $provider = new OsrmDistanceMatrixProvider($httpClient, $configManager, new NullLogger());

        $this->expectException(DistanceProviderUnavailableException::class);
        $this->expectExceptionMessage('OSRM returned non-numeric distance for pair [0, 2]: null (unroutable)');

        $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
            new Coordinate(60.0, 25.0),
        ]);
    }

    /**
     * Large matrix with an unroutable pair deep inside: confirms the
     * validation catches it regardless of position.
     */
    public function testBuildThrowsWhenOsrmReturnsNullInLargeMatrix(): void
    {
        $distances = [
            [0.0, 1000.0, 2000.0, 3000.0],
            [1000.0, 0.0, 1500.0, 2500.0],
            [2000.0, 1500.0, 0.0, 4000.0],
            [3000.0, 2500.0, null, 0.0],  // unreachable from [2] to [3]
        ];

        $httpClient = new MockHttpClient(new JsonMockResponse([
            'code' => 'Ok',
            'distances' => $distances,
        ]));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $provider = new OsrmDistanceMatrixProvider($httpClient, $configManager, new NullLogger());

        $this->expectException(DistanceProviderUnavailableException::class);
        $this->expectExceptionMessage('OSRM returned non-numeric distance for pair [3, 2]: null (unroutable)');

        $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
            new Coordinate(60.0, 25.0),
            new Coordinate(55.0, 10.0),
        ]);
    }

    /**
     * Valid matrix with all numeric distances (no nulls) must succeed,
     * confirming the fix doesn't break the happy path.
     */
    public function testBuildSucceedsWithAllNumericDistances(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse([
            'code' => 'Ok',
            'distances' => [
                [0.0, 1000.0, 2000.0],
                [1000.0, 0.0, 1500.0],
                [2000.0, 1500.0, 0.0],
            ],
        ]));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $provider = new OsrmDistanceMatrixProvider($httpClient, $configManager, new NullLogger());

        $matrix = $provider->build([
            new Coordinate(51.5074, -0.1278),
            new Coordinate(48.8566, 2.3522),
            new Coordinate(60.0, 25.0),
        ]);

        self::assertSame(0.0, $matrix->distanceBetween(0, 0));
        self::assertSame(1.0, $matrix->distanceBetween(0, 1));
        self::assertSame(2.0, $matrix->distanceBetween(0, 2));
    }
}
