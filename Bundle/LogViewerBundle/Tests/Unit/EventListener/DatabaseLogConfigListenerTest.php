<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\LogViewerBundle\EventListener\DatabaseLogConfigListener;
use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class DatabaseLogConfigListenerTest extends TestCase
{
    public function testEnablesHandlerOnKernelRequest(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        $config = $this->createConfigMock(true, 'ERROR', 'deferred', []);

        $listener = new DatabaseLogConfigListener($handler, $config);
        $listener->onKernelRequest($this->createMainRequest());

        self::assertTrue($handler->isEnabled());
        self::assertSame(Logger::ERROR, $handler->getLevel());
        self::assertSame(DatabaseLogHandler::MODE_DEFERRED, $handler->getWriteMode());
    }

    public function testEnablesHandlerOnConsoleCommand(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        $config = $this->createConfigMock(true, 'WARNING', 'immediate', ['app', 'security']);

        $listener = new DatabaseLogConfigListener($handler, $config);
        $listener->onConsoleCommand($this->createConsoleEvent());

        self::assertTrue($handler->isEnabled());
        self::assertSame(Logger::WARNING, $handler->getLevel());
        self::assertSame(DatabaseLogHandler::MODE_IMMEDIATE, $handler->getWriteMode());
        self::assertSame(['app', 'security'], $handler->getChannels());
    }

    public function testDisablesHandlerWhenConfigDisabled(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isDbLogEnabled')->willReturn(false);

        $listener = new DatabaseLogConfigListener($handler, $config);
        $listener->onKernelRequest($this->createMainRequest());

        self::assertFalse($handler->isEnabled());
    }

    public function testSetsChannelFilter(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        $config = $this->createConfigMock(true, 'DEBUG', 'deferred', ['security', 'app']);

        $listener = new DatabaseLogConfigListener($handler, $config);
        $listener->onKernelRequest($this->createMainRequest());

        self::assertSame(['security', 'app'], $handler->getChannels());
    }

    public function testSkipsSubRequest(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->expects(self::never())->method('isDbLogEnabled');

        $listener = new DatabaseLogConfigListener($handler, $config);

        $event = new RequestEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        );
        $listener->onKernelRequest($event);
    }

    public function testEnvVarOverridesConfigToEnable(): void
    {
        putenv('GENAKER_DB_LOG_ENABLED=1');

        try {
            $handler = new DatabaseLogHandler($this->createMock(Connection::class));
            $config = $this->createConfigMock(false, 'ERROR', 'deferred', []);

            $listener = new DatabaseLogConfigListener($handler, $config);
            $listener->onKernelRequest($this->createMainRequest());

            self::assertTrue($handler->isEnabled());
        } finally {
            putenv('GENAKER_DB_LOG_ENABLED');
        }
    }

    public function testEnvVarOverridesConfigToDisable(): void
    {
        putenv('GENAKER_DB_LOG_ENABLED=0');

        try {
            $handler = new DatabaseLogHandler($this->createMock(Connection::class));
            $config = $this->createConfigMock(true, 'ERROR', 'deferred', []);

            $listener = new DatabaseLogConfigListener($handler, $config);
            $listener->onKernelRequest($this->createMainRequest());

            self::assertFalse($handler->isEnabled());
        } finally {
            putenv('GENAKER_DB_LOG_ENABLED');
        }
    }

    public function testOnlyConfiguresOnce(): void
    {
        $handler = new DatabaseLogHandler($this->createMock(Connection::class));
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->expects(self::once())->method('isDbLogEnabled')->willReturn(true);
        $config->method('getDbLogLevel')->willReturn('WARNING');
        $config->method('getDbLogWriteMode')->willReturn('deferred');
        $config->method('getDbLogChannels')->willReturn([]);

        $listener = new DatabaseLogConfigListener($handler, $config);
        $listener->onKernelRequest($this->createMainRequest());
        $listener->onConsoleCommand($this->createConsoleEvent());
    }

    private function createMainRequest(): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST
        );
    }

    private function createConsoleEvent(): ConsoleCommandEvent
    {
        $command = new Command('test:dummy');

        return new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput());
    }

    private function createConfigMock(bool $enabled, string $level, string $writeMode, array $channels): PerfDashboardConfig
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isDbLogEnabled')->willReturn($enabled);
        $config->method('getDbLogLevel')->willReturn($level);
        $config->method('getDbLogWriteMode')->willReturn($writeMode);
        $config->method('getDbLogChannels')->willReturn($channels);

        return $config;
    }
}
