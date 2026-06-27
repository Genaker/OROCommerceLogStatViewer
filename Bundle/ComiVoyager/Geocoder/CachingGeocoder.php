<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Geocoder;

use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Entity\GeocodeCache;
use Genaker\Bundle\ComiVoyager\Repository\GeocodeCacheRepository;

/**
 * Decorates another geocoder with a database-backed cache, keyed by a hash
 * of the normalized address text.
 */
final class CachingGeocoder implements GeocoderInterface
{
    public function __construct(
        private readonly GeocoderInterface $inner,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $ttlDays,
    ) {
    }

    public function geocode(string $address): ?Coordinate
    {
        $hash = hash('sha256', mb_strtolower(trim($address)));

        /** @var GeocodeCacheRepository $repository */
        $repository = $this->entityManager->getRepository(GeocodeCache::class);

        $cached = $repository->findFreshByHash($hash, $this->ttlDays);
        if ($cached !== null) {
            return new Coordinate($cached->getLatitude(), $cached->getLongitude());
        }

        $coordinate = $this->inner->geocode($address);
        if ($coordinate === null) {
            return null;
        }

        $staleEntity = $repository->findByHash($hash);
        if ($staleEntity !== null) {
            $staleEntity
                ->setAddressText($address)
                ->setLatitude($coordinate->lat)
                ->setLongitude($coordinate->lng)
                ->setProvider($this->inner->getName());
            $this->entityManager->flush();

            return $coordinate;
        }

        $entity = (new GeocodeCache())
            ->setAddressHash($hash)
            ->setAddressText($address)
            ->setLatitude($coordinate->lat)
            ->setLongitude($coordinate->lng)
            ->setProvider($this->inner->getName());

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $coordinate;
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }
}
