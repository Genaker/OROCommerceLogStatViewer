<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Handler;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class DatabaseLogHandlerTest extends TestCase
{
    private Connection $connection;
    private DatabaseLogHandler $handler;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->handler = new DatabaseLogHandler($this->connection);
        $this->handler->setGroupingEnabled(false);
    }

    public function testDefaultDisabled(): void
    {
        self::assertFalse($this->handler->isEnabled());
    }

    public function testDefaultWriteModeIsDeferred(): void
    {
        self::assertSame(DatabaseLogHandler::MODE_DEFERRED, $this->handler->getWriteMode());
    }

    public function testSetEnabled(): void
    {
        $this->handler->setEnabled(true);
        self::assertTrue($this->handler->isEnabled());

        $this->handler->setEnabled(false);
        self::assertFalse($this->handler->isEnabled());
    }

    public function testSkipsWhenDisabled(): void
    {
        $this->connection->expects(self::never())->method('insert');

        $record = $this->createRecord();
        $this->handler->handle($record);
        $this->handler->flush();
    }

    // --- Deferred mode ---

    public function testDeferredModeBuffersWithoutWriting(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->connection->expects(self::never())->method('insert');

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'buffered'));
        self::assertSame(1, $this->handler->getBufferCount());
    }

    public function testFlushWritesBufferedRecords(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'first'));
        $this->handler->handle($this->createRecord(Logger::WARNING, 'WARNING', 'second'));
        self::assertSame(2, $this->handler->getBufferCount());

        $this->connection->expects(self::exactly(2))
            ->method('insert')
            ->with('genaker_log_entry', self::isType('array'));

        $this->handler->flush();
        self::assertSame(0, $this->handler->getBufferCount());
    }

    public function testFlushDoesNothingWhenBufferEmpty(): void
    {
        $this->handler->setEnabled(true);
        $this->connection->expects(self::never())->method('insert');

        $this->handler->flush();
    }

    // --- Immediate mode ---

    public function testImmediateModeWritesDirectly(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->connection->expects(self::once())
            ->method('insert')
            ->with('genaker_log_entry', self::callback(function (array $data): bool {
                return $data['channel'] === 'test'
                    && $data['level'] === Logger::ERROR
                    && str_contains($data['message'], 'Something broke');
            }));

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'Something broke', 'test'));
        self::assertSame(0, $this->handler->getBufferCount());
    }

    // --- Channel filtering ---

    public function testFiltersByChannel(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $this->handler->setChannels(['security', 'doctrine']);

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'allowed', 'security'));
        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'blocked', 'app'));
        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'allowed too', 'doctrine'));

        self::assertSame(2, $this->handler->getBufferCount());
    }

    public function testEmptyChannelsAllowsAll(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $this->handler->setChannels([]);

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'any', 'anything'));
        self::assertSame(1, $this->handler->getBufferCount());
    }

    // --- Data handling ---

    public function testSilentlyHandlesInsertFailure(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->connection->method('insert')
            ->willThrowException(new \RuntimeException('DB down'));

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'fail'));
        self::assertTrue(true);
    }

    public function testFlushSilentlyHandlesInsertFailure(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'buffered'));

        $this->connection->method('insert')
            ->willThrowException(new \RuntimeException('DB down'));

        $this->handler->flush();
        self::assertSame(0, $this->handler->getBufferCount());
    }

    public function testTruncatesLongMessage(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $longMessage = str_repeat('x', 70000);

        $this->connection->expects(self::once())
            ->method('insert')
            ->with('genaker_log_entry', self::callback(function (array $data) {
                return strlen($data['message']) <= 65535;
            }));

        $this->handler->handle($this->createRecord(Logger::INFO, 'INFO', $longMessage));
    }

    public function testContextAndExtraAreJsonEncoded(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->connection->expects(self::once())
            ->method('insert')
            ->with('genaker_log_entry', self::callback(function (array $data): bool {
                $context = json_decode($data['context'], true);
                $extra = json_decode($data['extra'], true);

                return $context['key'] === 'value' && $extra['extra_key'] === 'extra_value';
            }));

        $record = $this->createRecord(
            Logger::INFO,
            'INFO',
            'test',
            'app',
            ['key' => 'value'],
            ['extra_key' => 'extra_value']
        );
        $this->handler->handle($record);
    }

    public function testEmptyContextStoredAsNull(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->connection->expects(self::once())
            ->method('insert')
            ->with('genaker_log_entry', self::callback(function (array $data): bool {
                return $data['context'] === null && $data['extra'] === null;
            }));

        $this->handler->handle($this->createRecord());
    }

    public function testSetWriteModeRejectsInvalid(): void
    {
        $this->handler->setWriteMode('bogus');
        self::assertSame(DatabaseLogHandler::MODE_DEFERRED, $this->handler->getWriteMode());
    }

    public function testResetClearsBuffer(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'test'));
        self::assertSame(1, $this->handler->getBufferCount());

        $this->handler->reset();
        self::assertSame(0, $this->handler->getBufferCount());
    }

    // --- Grouping ---

    public function testGroupingEnabledByDefault(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        self::assertTrue($handler->isGroupingEnabled());
        self::assertSame(30, $handler->getGroupingKeyLength());
    }

    public function testGroupingUsesUpsertViaExecuteStatement(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setGroupingEnabled(true);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->connection->expects(self::never())->method('insert');
        $this->connection->expects(self::once())->method('executeStatement');

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'grouped'));
    }

    public function testGroupingDisabledUsesInsert(): void
    {
        $this->handler->setEnabled(true);
        $this->handler->setGroupingEnabled(false);
        $this->handler->setWriteMode(DatabaseLogHandler::MODE_IMMEDIATE);

        $this->connection->expects(self::once())->method('insert');

        $this->handler->handle($this->createRecord(Logger::ERROR, 'ERROR', 'not grouped'));
    }

    public function testGroupingKeyLengthClamps(): void
    {
        $this->handler->setGroupingKeyLength(5);
        self::assertSame(10, $this->handler->getGroupingKeyLength());

        $this->handler->setGroupingKeyLength(999);
        self::assertSame(255, $this->handler->getGroupingKeyLength());

        $this->handler->setGroupingKeyLength(50);
        self::assertSame(50, $this->handler->getGroupingKeyLength());
    }

    private function createRecord(
        int $level = Logger::DEBUG,
        string $levelName = 'DEBUG',
        string $message = 'test message',
        string $channel = 'app',
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
}
