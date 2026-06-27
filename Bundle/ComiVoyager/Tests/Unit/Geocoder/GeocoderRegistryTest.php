<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Geocoder;

use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Exception\GeocodingFailedException;
use Genaker\Bundle\ComiVoyager\Geocoder\CachingGeocoder;
use Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry
 */
final class GeocoderRegistryTest extends TestCase
{
    private function geocoder(string $name): GeocoderInterface
    {
        $geocoder = $this->createMock(GeocoderInterface::class);
        $geocoder->method('getName')->willReturn($name);

        return $geocoder;
    }

    public function testGetReturnsConfiguredDefaultGeocoder(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnMap([
            ['genaker_comi_voyager.geocoder', false, false, null, 'google'],
            ['genaker_comi_voyager.enable_geocode_cache', false, false, null, false],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $nominatim = $this->geocoder('nominatim');
        $google = $this->geocoder('google');

        $registry = new GeocoderRegistry([$nominatim, $google], $configManager, $entityManager);

        self::assertSame($google, $registry->get());
    }

    public function testGetReturnsExplicitGeocoderOverridingConfig(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnMap([
            ['genaker_comi_voyager.enable_geocode_cache', false, false, null, false],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $nominatim = $this->geocoder('nominatim');
        $google = $this->geocoder('google');

        $registry = new GeocoderRegistry([$nominatim, $google], $configManager, $entityManager);

        self::assertSame($nominatim, $registry->get('nominatim'));
    }

    public function testGetWrapsInCachingGeocoderWhenCacheEnabled(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnMap([
            ['genaker_comi_voyager.enable_geocode_cache', false, false, null, true],
            ['genaker_comi_voyager.geocode_cache_ttl_days', false, false, null, 14],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $registry = new GeocoderRegistry([$this->geocoder('nominatim')], $configManager, $entityManager);

        $resolved = $registry->get('nominatim');

        self::assertInstanceOf(CachingGeocoder::class, $resolved);
        self::assertSame('nominatim', $resolved->getName());
    }

    public function testGetThrowsForUnknownGeocoder(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $registry = new GeocoderRegistry([$this->geocoder('nominatim')], $configManager, $entityManager);

        $this->expectException(GeocodingFailedException::class);

        $registry->get('does-not-exist');
    }
}
