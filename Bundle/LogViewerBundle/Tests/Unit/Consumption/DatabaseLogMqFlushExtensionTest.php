<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Consumption;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\LogViewerBundle\Consumption\DatabaseLogMqFlushExtension;
use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Monolog\Logger;
use Oro\Component\MessageQueue\Consumption\Context;
use PHPUnit\Framework\TestCase;

class DatabaseLogMqFlushExtensionTest extends TestCase
{
    public function testFlushesOnPostReceived(): void
    {
        $connection = $this->createMock(Connection::class);
        $handler = new DatabaseLogHandler($connection);
        $handler->setEnabled(true);
        $handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $handler->setGroupingEnabled(false);

        $handler->handle([
            'message' => 'mq log', 'context' => [], 'level' => Logger::ERROR,
            'level_name' => 'ERROR', 'channel' => 'app',
            'datetime' => new \DateTimeImmutable(), 'extra' => [],
        ]);

        self::assertSame(1, $handler->getBufferCount());

        $connection->expects(self::once())->method('insert');

        $extension = new DatabaseLogMqFlushExtension($handler);
        $context = $this->createMock(Context::class);
        $extension->onPostReceived($context);

        self::assertSame(0, $handler->getBufferCount());
    }

    public function testNoopWhenBufferEmpty(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        $handler = new DatabaseLogHandler($connection);
        $handler->setEnabled(true);

        $extension = new DatabaseLogMqFlushExtension($handler);
        $context = $this->createMock(Context::class);
        $extension->onPostReceived($context);
    }
}
