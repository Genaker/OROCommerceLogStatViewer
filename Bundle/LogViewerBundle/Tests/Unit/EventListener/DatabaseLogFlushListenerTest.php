<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\LogViewerBundle\EventListener\DatabaseLogFlushListener;
use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

class DatabaseLogFlushListenerTest extends TestCase
{
    public function testFlushesOnKernelTerminate(): void
    {
        $connection = $this->createMock(Connection::class);
        $handler = $this->createBufferedHandler($connection);

        $connection->expects(self::once())->method('insert');

        $listener = new DatabaseLogFlushListener($handler);
        $listener->onKernelTerminate(new TerminateEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            new Response()
        ));

        self::assertSame(0, $handler->getBufferCount());
    }

    public function testFlushesOnConsoleTerminate(): void
    {
        $connection = $this->createMock(Connection::class);
        $handler = $this->createBufferedHandler($connection);

        $connection->expects(self::once())->method('insert');

        $listener = new DatabaseLogFlushListener($handler);
        $listener->onConsoleTerminate(new ConsoleTerminateEvent(
            new Command('test:cmd'),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));

        self::assertSame(0, $handler->getBufferCount());
    }

    public function testNoopWhenBufferEmpty(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        $handler = new DatabaseLogHandler($connection);
        $handler->setEnabled(true);

        $listener = new DatabaseLogFlushListener($handler);
        $listener->onKernelTerminate(new TerminateEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            new Response()
        ));
    }

    private function createBufferedHandler(Connection $connection): DatabaseLogHandler
    {
        $handler = new DatabaseLogHandler($connection);
        $handler->setEnabled(true);
        $handler->setWriteMode(DatabaseLogHandler::MODE_DEFERRED);
        $handler->setGroupingEnabled(false);

        $handler->handle([
            'message' => 'test', 'context' => [], 'level' => Logger::ERROR,
            'level_name' => 'ERROR', 'channel' => 'app',
            'datetime' => new \DateTimeImmutable(), 'extra' => [],
        ]);

        self::assertSame(1, $handler->getBufferCount());

        return $handler;
    }
}
