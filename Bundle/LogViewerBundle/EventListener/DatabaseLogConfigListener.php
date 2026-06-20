<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Monolog\Logger;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Toggles the DatabaseLogHandler on/off and configures level, write mode,
 * and channel filter based on system configuration.
 *
 * The handler is already in Monolog's chain (via compiler pass) but starts
 * disabled. This listener enables it and applies config on first request/command.
 */
class DatabaseLogConfigListener
{
    private bool $configured = false;

    public function __construct(
        private readonly DatabaseLogHandler $handler,
        private readonly PerfDashboardConfig $config,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->configure();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->configure();
    }

    private function configure(): void
    {
        if ($this->configured) {
            return;
        }

        $this->configured = true;

        $envOverride = getenv('GENAKER_DB_LOG_ENABLED');
        if ($envOverride !== false) {
            $enabled = filter_var($envOverride, FILTER_VALIDATE_BOOLEAN);
        } else {
            $enabled = $this->config->isDbLogEnabled();
        }

        if (!$enabled) {
            $this->handler->setEnabled(false);

            return;
        }

        $this->handler->setEnabled(true);

        $levelName = $this->config->getDbLogLevel();
        $level = Logger::toMonologLevel($levelName);
        $this->handler->setLevel($level);

        $this->handler->setWriteMode($this->config->getDbLogWriteMode());
        $this->handler->setChannels($this->config->getDbLogChannels());
        $this->handler->setMaxSizeMb($this->config->getDbLogMaxSizeMb());
        $this->handler->setTruncateIntervalMin($this->config->getDbLogTruncateIntervalMin());
        $this->handler->setGroupingEnabled($this->config->isDbLogGroupingEnabled());
        $this->handler->setGroupingKeyLength($this->config->getDbLogGroupingKeyLength());
    }
}
