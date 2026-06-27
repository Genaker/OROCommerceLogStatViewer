<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Consumption;

use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use Oro\Component\MessageQueue\Consumption\AbstractExtension;
use Oro\Component\MessageQueue\Consumption\Context;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reports server metrics and records per-topic MQ performance during message consumption.
 *
 * Timing: start captured in onBeforeReceive, duration written in onPostReceived.
 */
class PerfMqReportExtension extends AbstractExtension
{
    private float $processingStart = 0.0;

    public function __construct(
        private readonly ServerMetricsCollector $collector,
        private readonly PerfDashboardStore $store,
        private readonly PerfDashboardConfig $config,
        private readonly HttpPerformanceRecorder $perfRecorder,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    #[\Override]
    public function onBeforeReceive(Context $context): void
    {
        $this->processingStart = microtime(true);

        if (!$this->shouldReport() || !$this->config->shouldTriggerOnMqBefore()) {
            return;
        }

        $this->reportMetrics('onBeforeReceive');
    }

    #[\Override]
    public function onPostReceived(Context $context): void
    {
        $this->recordMqPerformance($context);

        if (!$this->shouldReport() || !$this->config->shouldTriggerOnMqAfter()) {
            return;
        }

        $this->reportMetrics('onPostReceived');
    }

    private function recordMqPerformance(Context $context): void
    {
        if ($this->processingStart === 0.0) {
            return;
        }

        try {
            $responseMs          = (microtime(true) - $this->processingStart) * 1000.0;
            $this->processingStart = 0.0;

            $topic = $this->extractTopic($context);
            $this->perfRecorder->record($topic, HttpPerformance::TYPE_MQ, $responseMs);
        } catch (\Throwable) {
            // Never break message processing
        }
    }

    private function extractTopic(Context $context): string
    {
        try {
            $message = $context->getMessage();
            if ($message === null) {
                return 'mq://unknown';
            }

            $props = $message->getProperties();
            foreach (['oro.message_queue.client.topic_name', 'topic_name', 'topic'] as $key) {
                if (!empty($props[$key])) {
                    return 'mq://' . $props[$key];
                }
            }

            // Fall back to a hash of the first 80 chars of the body
            $body = substr($message->getBody(), 0, 80);

            return 'mq://body:' . md5($body);
        } catch (\Throwable) {
            return 'mq://unknown';
        }
    }

    private function shouldReport(): bool
    {
        return $this->config->isEnabled() && $this->config->isMqReportingEnabled();
    }

    private function reportMetrics(string $hook): void
    {
        try {
            if (!$this->store->isReportDue()) {
                return;
            }

            $metrics = $this->collector->collect();
            $this->store->save($metrics);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'PerfMqReportExtension ({hook}): metrics report skipped due to error: {msg}',
                ['hook' => $hook, 'msg' => $exception->getMessage(), 'exception' => $exception]
            );
        }
    }
}
