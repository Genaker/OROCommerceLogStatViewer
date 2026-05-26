<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Automatically reports this instance's server metrics to the shared cache
 * on every Symfony kernel.terminate event (fires after the response is sent).
 *
 * Rate-limited to one report per PerfDashboardStore::REPORT_INTERVAL_SECONDS
 * per instance so that high-traffic servers do not hammer Redis on every request.
 */
class PerfReportListener
{
    public function __construct(
        private readonly ServerMetricsCollector $collector,
        private readonly PerfDashboardStore $store,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        try {
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
