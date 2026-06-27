<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\LogViewerBundle\Entity\SqlIssue;

/**
 * Repository for SqlIssue entities.
 */
class SqlIssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SqlIssue::class);
    }
}
