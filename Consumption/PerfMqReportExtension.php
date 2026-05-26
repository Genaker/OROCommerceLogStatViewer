<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Consumption;

use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use Oro\Component\MessageQueue\Consumption\AbstractExtension;
use Oro\Component\MessageQueue\Consumption\Context;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reports this consumer process's server metrics to the shared cache on every
 * iteration of the OroMQ consumer loop (onBeforeReceive).
 *
 * Rate-limited to once per PerfDashboardStore::REPORT_INTERVAL_SECONDS via the
 * same Redis rate-limit key used by PerfReportListener, so HTTP and MQ workers
 * on the same instance share the single 60-second window and never double-write.
 *
 * Registered via the oro_message_queue.consumption.extension tag — no HTTP
 * request or kernel.terminate event is required.
 */
class PerfMqReportExtension extends AbstractExtension
{
    public function __construct(
        private readonly ServerMetricsCollector $collector,
        private readonly PerfDashboardStore $store,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    #[\Override]
    public function onBeforeReceive(Context $context): void
    {
        try {
            if (!$this->store->isReportDue()) {
                return;
            }

            $metrics = $this->collector->collect();
            $this->store->save($metrics);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'PerfMqReportExtension: metrics report skipped due to error: {msg}',
                ['msg' => $exception->getMessage(), 'exception' => $exception]
            );
        }
    }
}
