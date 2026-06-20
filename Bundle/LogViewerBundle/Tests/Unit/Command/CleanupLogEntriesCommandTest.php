<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Genaker\Bundle\LogViewerBundle\Command\CleanupLogEntriesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupLogEntriesCommandTest extends TestCase
{
    public function testDeletesOldEntries(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->expects(self::once())->method('execute')->willReturn(42);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);

        $command = new CleanupLogEntriesCommand($connection);
        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['--hours' => '48']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 42 log entries', $tester->getDisplay());
        self::assertStringContainsString('48 hours', $tester->getDisplay());
    }

    public function testDefaultRetentionIs24Hours(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('execute')->willReturn(0);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);

        $command = new CleanupLogEntriesCommand($connection);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('24 hours', $tester->getDisplay());
    }

    public function testHandlesDbFailure(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('execute')->willThrowException(new \RuntimeException('DB down'));

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);

        $command = new CleanupLogEntriesCommand($connection);

        $tester = new CommandTester($command);
        $tester->execute(['--hours' => '12']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Cleanup failed', $tester->getDisplay());
    }

    public function testMinimumHoursIsOne(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('execute')->willReturn(5);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);

        $command = new CleanupLogEntriesCommand($connection);

        $tester = new CommandTester($command);
        $tester->execute(['--hours' => '0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 hours', $tester->getDisplay());
    }
}
