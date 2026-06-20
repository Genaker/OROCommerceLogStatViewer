<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Geocoder;

use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Entity\GeocodeCache;
use Genaker\Bundle\ComiVoyager\Geocoder\CachingGeocoder;
use Genaker\Bundle\ComiVoyager\Repository\GeocodeCacheRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Geocoder\CachingGeocoder
 */
final class CachingGeocoderTest extends TestCase
{
    public function testReturnsCachedCoordinateWithoutCallingInner(): void
    {
        $cached = (new GeocodeCache())
            ->setAddressHash('hash')
            ->setAddressText('221B Baker Street, London')
            ->setLatitude(51.5237)
            ->setLongitude(-0.1585)
            ->setProvider('nominatim');

        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->expects(self::once())
            ->method('findFreshByHash')
            ->willReturn($cached);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->expects(self::never())->method('geocode');

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        $coordinate = $geocoder->geocode('221B Baker Street, London');

        self::assertSame(51.5237, $coordinate->lat);
        self::assertSame(-0.1585, $coordinate->lng);
    }

    public function testCallsInnerAndPersistsOnCacheMiss(): void
    {
        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->method('findFreshByHash')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GeocodeCache::class));
        $entityManager->expects(self::once())->method('flush');

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('getName')->willReturn('nominatim');
        $inner->expects(self::once())
            ->method('geocode')
            ->with('Eiffel Tower, Paris')
            ->willReturn(new Coordinate(48.8584, 2.2945));

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        $coordinate = $geocoder->geocode('Eiffel Tower, Paris');

        self::assertSame(48.8584, $coordinate->lat);
        self::assertSame(2.2945, $coordinate->lng);
    }

    public function testReturnsNullAndDoesNotPersistWhenInnerFails(): void
    {
        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->method('findFreshByHash')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('geocode')->willReturn(null);

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        self::assertNull($geocoder->geocode('Nowhere'));
    }

    public function testGetNameDelegatesToInner(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('getName')->willReturn('google');

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        self::assertSame('google', $geocoder->getName());
    }

    /**
     * Regression: when a cached entry expires (TTL > now), findFreshByHash()
     * returns null, but the stale row remains in the DB. Attempting to persist
     * a new entity with the same hash causes a UNIQUE constraint violation.
     * Fix: update the stale row instead of creating a new one.
     */
    public function testUpdatesStaleEntryInsteadOfCreatingDuplicate(): void
    {
        $address = '221B Baker Street, London';
        $hash = hash('sha256', mb_strtolower(trim($address)));

        $stale = (new GeocodeCache())
            ->setAddressHash($hash)
            ->setAddressText('old text')
            ->setLatitude(0.0)
            ->setLongitude(0.0)
            ->setProvider('nominatim');

        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->method('findFreshByHash')->willReturn(null); // expired
        $repository->method('findByHash')->willReturn($stale); // but row exists

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist'); // must NOT persist new
        $entityManager->expects(self::once())->method('flush'); // must flush the updated entity

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('getName')->willReturn('nominatim');
        $inner->method('geocode')->willReturn(new Coordinate(51.5237, -0.1585));

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        $coordinate = $geocoder->geocode($address);

        self::assertSame(51.5237, $coordinate->lat);
        self::assertSame(-0.1585, $coordinate->lng);
        self::assertSame('nominatim', $stale->getProvider());
        self::assertSame($address, $stale->getAddressText());
        self::assertSame(51.5237, $stale->getLatitude());
        self::assertSame(-0.1585, $stale->getLongitude());
    }

    /**
     * Stale cache with different coordinates: the update must refresh both
     * lat/lng and provider, not just create a duplicate row.
     */
    public function testUpdatesStaleEntryWithNewCoordinates(): void
    {
        $address = '10 Downing Street, London';
        $hash = hash('sha256', mb_strtolower(trim($address)));

        $stale = (new GeocodeCache())
            ->setAddressHash($hash)
            ->setAddressText('old address text')
            ->setLatitude(40.0)
            ->setLongitude(-74.0)
            ->setProvider('google');

        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->method('findFreshByHash')->willReturn(null);
        $repository->method('findByHash')->willReturn($stale);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('getName')->willReturn('nominatim');
        $inner->method('geocode')->willReturn(new Coordinate(51.5033, -0.1276));

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        $coordinate = $geocoder->geocode($address);

        self::assertSame(51.5033, $coordinate->lat);
        self::assertSame(-0.1276, $coordinate->lng);
        self::assertSame('nominatim', $stale->getProvider());
        self::assertSame(51.5033, $stale->getLatitude());
        self::assertSame(-0.1276, $stale->getLongitude());
    }

    /**
     * Stale cache with a different provider: when re-geocoding with a new
     * provider (e.g., switched from Google to Nominatim), the stale row's
     * provider field must be updated to reflect the new source.
     */
    public function testUpdatesStaleEntryProviderWhenSwitchingGeocoder(): void
    {
        $address = 'Big Ben, London';
        $hash = hash('sha256', mb_strtolower(trim($address)));

        $stale = (new GeocodeCache())
            ->setAddressHash($hash)
            ->setAddressText($address)
            ->setLatitude(51.5007)
            ->setLongitude(-0.1246)
            ->setProvider('google');

        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->method('findFreshByHash')->willReturn(null);
        $repository->method('findByHash')->willReturn($stale);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('getName')->willReturn('nominatim');
        $inner->method('geocode')->willReturn(new Coordinate(51.5007, -0.1246));

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        $geocoder->geocode($address);

        self::assertSame('nominatim', $stale->getProvider());
    }

    /**
     * No stale entry + no fresh entry = new insertion. Confirms the update
     * path doesn't break the original new-entry path.
     */
    public function testCreatesNewEntryWhenNoCacheExists(): void
    {
        $address = 'Tower Bridge, London';
        $hash = hash('sha256', mb_strtolower(trim($address)));

        $repository = $this->createMock(GeocodeCacheRepository::class);
        $repository->method('findFreshByHash')->willReturn(null);
        $repository->method('findByHash')->willReturn(null); // no stale entry either

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(GeocodeCache::class));
        $entityManager->expects(self::once())->method('flush');

        $inner = $this->createMock(GeocoderInterface::class);
        $inner->method('getName')->willReturn('nominatim');
        $inner->method('geocode')->willReturn(new Coordinate(51.5055, -0.0754));

        $geocoder = new CachingGeocoder($inner, $entityManager, 30);

        $coordinate = $geocoder->geocode($address);

        self::assertSame(51.5055, $coordinate->lat);
        self::assertSame(-0.0754, $coordinate->lng);
    }
}
