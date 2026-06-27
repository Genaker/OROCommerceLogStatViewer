<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\LoggerChain;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\SqlPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\SqlQueryCollector;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Listens to HTTP and console lifecycle events to track SQL N+1 and slow query issues.
 */
class SqlPerformanceListener
{
    private bool $collectorChained = false;

    public function __construct(
        private readonly SqlQueryCollector $collector,
        private readonly SqlPerformanceRecorder $recorder,
        private readonly Connection $connection,
        private readonly PerfDashboardConfig $config,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->ensureCollectorChained();
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $url     = $request->getPathInfo();
        $qs      = $request->getQueryString();

        if ($qs !== null && $qs !== '') {
            $url .= '?' . $qs;
        }

        $issues = $this->collector->getIssues(
            $url,
            $this->config->getSqlN1Threshold(),
            $this->config->getSqlSlowThresholdMs()
        );

        $this->recorder->flush($issues);
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->ensureCollectorChained();

        $commandName = $event->getCommand()?->getName() ?? 'unknown';
        $url         = 'cli:' . $commandName;

        $issues = $this->collector->getIssues(
            $url,
            $this->config->getSqlN1Threshold(),
            $this->config->getSqlSlowThresholdMs()
        );

        $this->recorder->flush($issues);
    }

    private function ensureCollectorChained(): void
    {
        if ($this->collectorChained || !$this->config->isSqlTrackingEnabled()) {
            return;
        }

        $existing = $this->connection->getConfiguration()->getSQLLogger();

        if ($existing instanceof LoggerChain) {
            $this->connection->getConfiguration()->setSQLLogger(
                new LoggerChain([$existing, $this->collector])
            );
        } elseif ($existing !== null) {
            $this->connection->getConfiguration()->setSQLLogger(
                new LoggerChain([$existing, $this->collector])
            );
        } else {
            $this->connection->getConfiguration()->setSQLLogger($this->collector);
        }

        $this->collectorChained = true;
    }
}
