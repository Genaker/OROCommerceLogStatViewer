<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;

class HttpPerformanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HttpPerformance::class);
    }

    public function findByPathAndType(string $path, string $type): ?HttpPerformance
    {
        return $this->findOneBy(['path' => $path, 'type' => $type]);
    }
}
