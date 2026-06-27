<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Repository;

use Doctrine\ORM\EntityRepository;
use Genaker\Bundle\ComiVoyager\Entity\GeocodeCache;

class GeocodeCacheRepository extends EntityRepository
{
    public function findFreshByHash(string $hash, int $ttlDays): ?GeocodeCache
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $ttlDays));

        return $this->createQueryBuilder('gc')
            ->andWhere('gc.addressHash = :hash')
            ->andWhere('gc.createdAt > :cutoff')
            ->setParameter('hash', $hash)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Fetches any cached result for this address hash, regardless of TTL.
     * Used to update stale entries (fixing the unique constraint violation
     * that occurs when re-geocoding after TTL expiry).
     */
    public function findByHash(string $hash): ?GeocodeCache
    {
        return $this->createQueryBuilder('gc')
            ->andWhere('gc.addressHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
