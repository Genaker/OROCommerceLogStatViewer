<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Distance;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Distance\DistanceProviderRegistry;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Distance\DistanceProviderRegistry
 */
final class DistanceProviderRegistryTest extends TestCase
{
    private function provider(string $name): DistanceMatrixProviderInterface
    {
        $provider = $this->createMock(DistanceMatrixProviderInterface::class);
        $provider->method('getName')->willReturn($name);

        return $provider;
    }

    public function testGetReturnsConfiguredDefaultProvider(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')
            ->with('genaker_comi_voyager.distance_provider')
            ->willReturn('vincenty');

        $haversine = $this->provider('haversine');
        $vincenty = $this->provider('vincenty');

        $registry = new DistanceProviderRegistry([$haversine, $vincenty], $configManager);

        self::assertSame($vincenty, $registry->get());
    }

    public function testGetFallsBackToHaversineWhenNotConfigured(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $haversine = $this->provider('haversine');

        $registry = new DistanceProviderRegistry([$haversine], $configManager);

        self::assertSame($haversine, $registry->get());
    }

    public function testGetReturnsExplicitProviderOverridingConfig(): void
    {
        $configManager = $this->createMock(ConfigManager::class);

        $haversine = $this->provider('haversine');
        $osrm = $this->provider('osrm');

        $registry = new DistanceProviderRegistry([$haversine, $osrm], $configManager);

        self::assertSame($osrm, $registry->get('osrm'));
    }

    public function testGetThrowsForUnknownProvider(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $registry = new DistanceProviderRegistry([$this->provider('haversine')], $configManager);

        $this->expectException(DistanceProviderUnavailableException::class);

        $registry->get('does-not-exist');
    }
}
