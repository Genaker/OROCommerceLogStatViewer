<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\LogViewerBundle\Command\CleanupLogEntriesCommand;
use Genaker\Bundle\LogViewerBundle\Consumption\DatabaseLogMqFlushExtension;
use Genaker\Bundle\LogViewerBundle\EventListener\DatabaseLogFlushListener;
use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Monolog\Logger;
use Oro\Component\MessageQueue\Consumption\Context;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Integration tests for the DB log pipeline against the real local PostgreSQL database.
 *
 * Uses the same DBAL connection strategy as the other integration tests in this project.
 * All inserted rows use a sentinel channel ('_test_dblog') and are cleaned up in tearDown.
 *
 * Run:
 *   INTEGRATION_TESTS_ENABLED=1 php bin/phpunit -c phpunit-dev.xml \
 *     --filter DatabaseLogIntegrationTest --no-coverage
 */
class DatabaseLogIntegrationTest extends TestCase
{
    private const string SENTINEL_CHANNEL = '_test_dblog';

    private ?Connection $connection = null;
    private ?DatabaseLogHandler $handler = null;

    protected function setUp(): void
    {
        if (getenv('INTEGRATION_TESTS_ENABLED') !== '1') {
            $this->markTestSkipped('Integration tests disabled (set INTEGRATION_TESTS_ENABLED=1).');
        }

        $dbUrl = getenv('ORO_DB_URL');
        if (!$dbUrl) {
            $this->markTestSkipped('ORO_DB_URL not set.');
        }

        try {
            $this->connection = \Doctrine\DBAL\DriverManager::getConnection(['url' => $dbUrl]);
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unreachable: ' . $e->getMessage());
        }

        if (!$this->tableExists()) {
            $this->createTable();
        } else {
            $this->ensureGroupingColumns();
        }

        $this->cleanupSentinel();

        $this->handler = new DatabaseLogHandler($this->connection);
        $this->handler->setEnabled(true);
        $this->handler->setChannels([self::SENTINEL_CHANNEL]);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanupSentinel();
            $this->connection->close();
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Immediate mode
    // ──────────────────────────────────────────────────────────────

    public function testImmediateModeWritesToDb(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'immediate test'));

        $rows = $this->fetchSentinel();
        self::assertCount(1, $rows);
        self::assertSame('immediate test', $rows[0]['message']);
        self::assertSame(self::SENTINEL_CHANNEL, $rows[0]['channel']);
        self::assertSame('ERROR', $rows[0]['level_name']);
    }

    public function testImmediateMultipleEntries(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->handler->handle($this->record(Logger::WARNING, 'WARNING', 'warn msg'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'err msg'));
        $this->handler->handle($this->record(Logger::CRITICAL, 'CRITICAL', 'crit msg'));

        self::assertCount(3, $this->fetchSentinel());
    }

    // ──────────────────────────────────────────────────────────────
    // Deferred mode + kernel.terminate
    // ──────────────────────────────────────────────────────────────

    public function testDeferredFlushesOnKernelTerminate(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'deferred 1'));
        $this->handler->handle($this->record(Logger::WARNING, 'WARNING', 'deferred 2'));

        self::assertCount(0, $this->fetchSentinel(), 'No rows before flush');

        $listener = new DatabaseLogFlushListener($this->handler);
        $listener->onKernelTerminate(new TerminateEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            new Response()
        ));

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);
        self::assertSame('deferred 1', $rows[0]['message']);
        self::assertSame('deferred 2', $rows[1]['message']);
        self::assertSame(0, $this->handler->getBufferCount());
    }

    // ──────────────────────────────────────────────────────────────
    // Deferred mode + console.terminate
    // ──────────────────────────────────────────────────────────────

    public function testDeferredFlushesOnConsoleTerminate(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'cli log'));

        $listener = new DatabaseLogFlushListener($this->handler);
        $listener->onConsoleTerminate(new ConsoleTerminateEvent(
            new Command('test:cmd'),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));

        self::assertCount(1, $this->fetchSentinel());
    }

    // ──────────────────────────────────────────────────────────────
    // Deferred mode + MQ onPostReceived
    // ──────────────────────────────────────────────────────────────

    public function testDeferredFlushesOnMqPostReceived(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'mq log'));

        $extension = new DatabaseLogMqFlushExtension($this->handler);
        $extension->onPostReceived($this->createMock(Context::class));

        self::assertCount(1, $this->fetchSentinel());
    }

    // ──────────────────────────────────────────────────────────────
    // Level filtering
    // ──────────────────────────────────────────────────────────────

    public function testLevelFiltering(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setLevel(Logger::ERROR);

        $this->handler->handle($this->record(Logger::DEBUG, 'DEBUG', 'skip debug'));
        $this->handler->handle($this->record(Logger::INFO, 'INFO', 'skip info'));
        $this->handler->handle($this->record(Logger::WARNING, 'WARNING', 'skip warning'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'keep error'));
        $this->handler->handle($this->record(Logger::CRITICAL, 'CRITICAL', 'keep critical'));

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);

        $messages = array_column($rows, 'message');
        self::assertContains('keep error', $messages);
        self::assertContains('keep critical', $messages);
    }

    // ──────────────────────────────────────────────────────────────
    // Context & extra stored as JSON
    // ──────────────────────────────────────────────────────────────

    public function testContextAndExtraPersistedAsJson(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->handler->handle($this->record(
            Logger::INFO,
            'INFO',
            'json test',
            self::SENTINEL_CHANNEL,
            ['userId' => 42, 'action' => 'checkout'],
            ['requestId' => 'req-abc']
        ));

        $row = $this->fetchSentinel()[0];
        $context = json_decode($row['context'], true);
        $extra = json_decode($row['extra'], true);

        self::assertSame(42, $context['userId']);
        self::assertSame('checkout', $context['action']);
        self::assertSame('req-abc', $extra['requestId']);
    }

    // ──────────────────────────────────────────────────────────────
    // Cleanup command
    // ──────────────────────────────────────────────────────────────

    public function testCleanupDeletesOldEntries(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'fresh row'));

        // Insert an old sentinel row manually
        $this->connection->insert('genaker_log_entry', [
            'channel'    => self::SENTINEL_CHANNEL,
            'level'      => Logger::ERROR,
            'level_name' => 'ERROR',
            'message'    => 'old row',
            'context'    => null,
            'extra'      => null,
            'created_at' => (new \DateTime('-72 hours'))->format('Y-m-d H:i:s'),
            'url'        => null,
            'ip'         => null,
        ]);

        self::assertCount(2, $this->fetchSentinel());

        $command = new CleanupLogEntriesCommand($this->connection);
        $tester = new CommandTester($command);
        $tester->execute(['--hours' => '24']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Deleted', $tester->getDisplay());

        $rows = $this->fetchSentinel();
        self::assertCount(1, $rows);
        self::assertSame('fresh row', $rows[0]['message']);
    }

    // ──────────────────────────────────────────────────────────────
    // Multiple flush cycles don't duplicate
    // ──────────────────────────────────────────────────────────────

    public function testMultipleFlushCyclesDontDuplicate(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $listener = new DatabaseLogFlushListener($this->handler);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'cycle 1'));
        $listener->onKernelTerminate(new TerminateEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            new Response()
        ));

        $this->handler->handle($this->record(Logger::WARNING, 'WARNING', 'cycle 2'));
        $listener->onKernelTerminate(new TerminateEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            new Response()
        ));

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);
    }

    // ──────────────────────────────────────────────────────────────
    // End-to-end: real Logger → Handler → DB
    // ──────────────────────────────────────────────────────────────

    public function testEndToEndLoggerWritesToDbWhenEnabled(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setLevel(Logger::DEBUG);

        $logger = new Logger(self::SENTINEL_CHANNEL);
        $logger->pushHandler($this->handler);

        $logger->debug('e2e debug message');
        $logger->info('e2e info message', ['userId' => 7]);
        $logger->warning('e2e warning message');
        $logger->error('e2e error message', ['code' => 500]);
        $logger->critical('e2e critical message');

        $rows = $this->fetchSentinel();
        self::assertCount(5, $rows);

        $messages = array_column($rows, 'message');
        self::assertContains('e2e debug message', $messages);
        self::assertContains('e2e info message', $messages);
        self::assertContains('e2e warning message', $messages);
        self::assertContains('e2e error message', $messages);
        self::assertContains('e2e critical message', $messages);

        $levels = array_column($rows, 'level_name');
        self::assertContains('DEBUG', $levels);
        self::assertContains('INFO', $levels);
        self::assertContains('WARNING', $levels);
        self::assertContains('ERROR', $levels);
        self::assertContains('CRITICAL', $levels);

        foreach ($rows as $row) {
            self::assertSame(self::SENTINEL_CHANNEL, $row['channel']);
            self::assertNotEmpty($row['created_at']);
        }
    }

    public function testEndToEndLoggerSkipsDbWhenDisabled(): void
    {
        $this->handler->setEnabled(false);

        $logger = new Logger(self::SENTINEL_CHANNEL);
        $logger->pushHandler($this->handler);

        $logger->error('should not appear in DB');
        $logger->critical('also should not appear');

        self::assertCount(0, $this->fetchSentinel());
    }

    public function testEndToEndLoggerRespectsLevelFilter(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setLevel(Logger::WARNING);

        $logger = new Logger(self::SENTINEL_CHANNEL);
        $logger->pushHandler($this->handler);

        $logger->debug('skip this');
        $logger->info('skip this too');
        $logger->warning('keep this warning');
        $logger->error('keep this error');

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);

        $messages = array_column($rows, 'message');
        self::assertContains('keep this warning', $messages);
        self::assertContains('keep this error', $messages);
    }

    public function testEndToEndDeferredLoggerWritesOnFlush(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $this->handler->setLevel(Logger::DEBUG);

        $logger = new Logger(self::SENTINEL_CHANNEL);
        $logger->pushHandler($this->handler);

        $logger->info('deferred e2e 1');
        $logger->error('deferred e2e 2');

        self::assertCount(0, $this->fetchSentinel(), 'No rows in DB before flush');
        self::assertSame(2, $this->handler->getBufferCount());

        $this->handler->flush();

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);
        self::assertSame(0, $this->handler->getBufferCount());
        self::assertSame('deferred e2e 1', $rows[0]['message']);
        self::assertSame('deferred e2e 2', $rows[1]['message']);
    }

    public function testEndToEndContextPreservedInDb(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setLevel(Logger::DEBUG);

        $logger = new Logger(self::SENTINEL_CHANNEL);
        $logger->pushHandler($this->handler);

        $logger->error('order failed', [
            'orderId' => 12345,
            'reason'  => 'payment_declined',
            'amount'  => 99.95,
        ]);

        $rows = $this->fetchSentinel();
        self::assertCount(1, $rows);

        $context = json_decode($rows[0]['context'], true);
        self::assertSame(12345, $context['orderId']);
        self::assertSame('payment_declined', $context['reason']);
        self::assertSame(99.95, $context['amount']);
    }

    public function testEndToEndLoggerPresentInDbAfterPageSimulation(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $this->handler->setLevel(Logger::DEBUG);

        $logger = new Logger(self::SENTINEL_CHANNEL);
        $logger->pushHandler($this->handler);

        $logger->info('Request started');
        $logger->debug('Loading entity', ['id' => 42]);
        $logger->warning('Slow query detected', ['ms' => 1200]);
        $logger->info('Response sent');

        self::assertCount(0, $this->fetchSentinel(), 'Deferred: nothing in DB during request');

        $listener = new DatabaseLogFlushListener($this->handler);
        $listener->onKernelTerminate(new TerminateEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            new Response()
        ));

        $rows = $this->fetchSentinel();
        self::assertCount(4, $rows);
        self::assertSame('Request started', $rows[0]['message']);
        self::assertSame('Response sent', $rows[3]['message']);

        $slowQueryRow = $rows[2];
        self::assertSame('WARNING', $slowQueryRow['level_name']);
        $ctx = json_decode($slowQueryRow['context'], true);
        self::assertSame(1200, $ctx['ms']);
    }

    // ──────────────────────────────────────────────────────────────
    // Grouping / deduplication
    // ──────────────────────────────────────────────────────────────

    public function testGroupingMergesDuplicateMessages(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setGroupingEnabled(true);
        $this->handler->setGroupingKeyLength(30);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Connection refused to host db.example.com'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Connection refused to host db.example.com'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Connection refused to host db.example.com'));

        $rows = $this->fetchSentinel();
        self::assertCount(1, $rows);
        self::assertEquals(3, $rows[0]['occurrence_count']);
    }

    public function testGroupingKeepsDifferentMessagesSepa‌rate(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setGroupingEnabled(true);
        $this->handler->setGroupingKeyLength(30);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Connection refused'));
        $this->handler->handle($this->record(Logger::WARNING, 'WARNING', 'Slow query detected'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Timeout waiting for lock'));

        $rows = $this->fetchSentinel();
        self::assertCount(3, $rows);
    }

    public function testGroupingDisabledCreatesDuplicates(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setGroupingEnabled(false);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Same message'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Same message'));

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);
    }

    public function testGroupingSamePrefixDifferentLevelStaysSeparate(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setGroupingEnabled(true);
        $this->handler->setGroupingKeyLength(30);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Database connection failed'));
        $this->handler->handle($this->record(Logger::WARNING, 'WARNING', 'Database connection failed'));

        $rows = $this->fetchSentinel();
        self::assertCount(2, $rows);
    }

    public function testGroupingUpdatesLatestContextAndTimestamp(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);
        $this->handler->setGroupingEnabled(true);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'API timeout', self::SENTINEL_CHANNEL, ['attempt' => 1]));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'API timeout', self::SENTINEL_CHANNEL, ['attempt' => 2]));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'API timeout', self::SENTINEL_CHANNEL, ['attempt' => 3]));

        $rows = $this->fetchSentinel();
        self::assertCount(1, $rows);
        self::assertEquals(3, $rows[0]['occurrence_count']);

        $context = json_decode($rows[0]['context'], true);
        self::assertSame(3, $context['attempt']);
    }

    public function testGroupingDeferredModeWorksWithFlush(): void
    {
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $this->handler->setGroupingEnabled(true);

        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Repeated error in deferred'));
        $this->handler->handle($this->record(Logger::ERROR, 'ERROR', 'Repeated error in deferred'));

        self::assertSame(2, $this->handler->getBufferCount());
        $this->handler->flush();

        $rows = $this->fetchSentinel();
        self::assertCount(1, $rows);
        self::assertEquals(2, $rows[0]['occurrence_count']);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function record(
        int $level,
        string $levelName,
        string $message,
        string $channel = self::SENTINEL_CHANNEL,
        array $context = [],
        array $extra = [],
    ): array {
        return [
            'message'    => $message,
            'context'    => $context,
            'level'      => $level,
            'level_name' => $levelName,
            'channel'    => $channel,
            'datetime'   => new \DateTimeImmutable(),
            'extra'      => $extra,
        ];
    }

    private function fetchSentinel(): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('genaker_log_entry')
            ->where('channel = :ch')
            ->setParameter('ch', self::SENTINEL_CHANNEL)
            ->orderBy('id', 'ASC');

        return $qb->execute()->fetchAllAssociative();
    }

    private function cleanupSentinel(): void
    {
        try {
            $qb = $this->connection->createQueryBuilder()
                ->delete('genaker_log_entry')
                ->where('channel = :ch')
                ->setParameter('ch', self::SENTINEL_CHANNEL);
            $qb->execute();
        } catch (\Throwable) {
            // Table might not exist yet
        }
    }

    private function tableExists(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1 FROM genaker_log_entry LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createTable(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS genaker_log_entry (
                id               BIGSERIAL PRIMARY KEY,
                channel          VARCHAR(64)  NOT NULL,
                level            SMALLINT     NOT NULL,
                level_name       VARCHAR(20)  NOT NULL,
                message          TEXT         NOT NULL,
                context          JSONB,
                extra            JSONB,
                created_at       TIMESTAMP    NOT NULL,
                url              VARCHAR(2000),
                ip               VARCHAR(45),
                message_key      VARCHAR(64),
                occurrence_count INTEGER      NOT NULL DEFAULT 1,
                first_seen_at    TIMESTAMP
            )
        ');
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_log_entry_channel ON genaker_log_entry (channel)
        ');
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_log_entry_level ON genaker_log_entry (level)
        ');
        $this->connection->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_log_entry_created ON genaker_log_entry (created_at)
        ');
        $this->connection->executeStatement('
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_log_entry_message_key ON genaker_log_entry (message_key)
        ');
    }

    private function ensureGroupingColumns(): void
    {
        try {
            $this->connection->executeQuery("SELECT message_key FROM genaker_log_entry LIMIT 1");
        } catch (\Throwable) {
            $this->connection->executeStatement('ALTER TABLE genaker_log_entry ADD COLUMN IF NOT EXISTS message_key VARCHAR(64)');
            $this->connection->executeStatement('ALTER TABLE genaker_log_entry ADD COLUMN IF NOT EXISTS occurrence_count INTEGER NOT NULL DEFAULT 1');
            $this->connection->executeStatement('ALTER TABLE genaker_log_entry ADD COLUMN IF NOT EXISTS first_seen_at TIMESTAMP');
            $this->connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_log_entry_message_key ON genaker_log_entry (message_key)');
        }
    }
}
