<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Fires on kernel.terminate (after response is sent) to:
 *  1. Report server metrics to the shared cache (rate-limited).
 *  2. Record per-path HTTP performance into genaker_http_performance (always).
 */
class PerfReportListener
{
    public function __construct(
        private readonly ServerMetricsCollector $collector,
        private readonly PerfDashboardStore $store,
        private readonly PerfDashboardConfig $config,
        private readonly HttpPerformanceRecorder $perfRecorder,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->recordHttpPerformance($event);
        $this->reportServerMetrics();
    }

    private function recordHttpPerformance(TerminateEvent $event): void
    {
        try {
            $requestStart = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            $responseMs   = (microtime(true) - $requestStart) * 1000.0;

            $path       = HttpPerformanceRecorder::normalizePath($event->getRequest()->getPathInfo());
            $statusCode = $event->getResponse()->getStatusCode();

            $this->perfRecorder->record($path, HttpPerformance::TYPE_HTTP, $responseMs, $statusCode);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'PerfReportListener: HTTP perf record failed: {msg}',
                ['msg' => $exception->getMessage()]
            );
        }
    }

    private function reportServerMetrics(): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isHttpReportingEnabled()) {
                return;
            }

            if (!$this->store->isReportDue()) {
                return;
            }

            $metrics = $this->collector->collect();
            $this->store->save($metrics);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'PerfReportListener: metrics report skipped due to error: {msg}',
                ['msg' => $exception->getMessage(), 'exception' => $exception]
            );
        }
    }
}
