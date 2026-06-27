<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Consumption;

use Genaker\Bundle\LogViewerBundle\Consumption\PerfMqReportExtension;
use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use Oro\Component\MessageQueue\Consumption\Context;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PerfMqReportExtensionTest extends TestCase
{
    private ServerMetricsCollector&MockObject $collector;
    private PerfDashboardStore&MockObject $store;
    private PerfDashboardConfig&MockObject $config;
    private HttpPerformanceRecorder&MockObject $perfRecorder;
    private PerfMqReportExtension $extension;

    protected function setUp(): void
    {
        $this->collector    = $this->createMock(ServerMetricsCollector::class);
        $this->store        = $this->createMock(PerfDashboardStore::class);
        $this->config       = $this->createMock(PerfDashboardConfig::class);
        $this->perfRecorder = $this->createMock(HttpPerformanceRecorder::class);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isMqReportingEnabled')->willReturn(true);
        $this->config->method('shouldTriggerOnMqBefore')->willReturn(true);
        $this->config->method('shouldTriggerOnMqAfter')->willReturn(true);

        $this->extension = new PerfMqReportExtension(
            $this->collector,
            $this->store,
            $this->config,
            $this->perfRecorder
        );
    }

    // ── Server-metrics reporting (preserved behaviour) ─────────────────────────

    /** @test */
    public function testOnBeforeReceiveSkipsServerMetricsWhenReportNotDue(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $this->collector->expects($this->never())->method('collect');
        $this->store->expects($this->never())->method('save');

        $this->extension->onBeforeReceive($this->createMock(Context::class));
    }

    /** @test */
    public function testOnBeforeReceiveCollectsAndSavesServerMetricsWhenDue(): void
    {
        $metrics = ['instanceId' => 'abc123', 'hostname' => 'mq-worker-01'];

        $this->store->method('isReportDue')->willReturn(true);
        $this->collector->expects($this->once())->method('collect')->willReturn($metrics);
        $this->store->expects($this->once())->method('save')->with($metrics);

        $this->extension->onBeforeReceive($this->createMock(Context::class));
    }

    /** @test */
    public function testServerMetricsAreCalledOnEveryDueReport(): void
    {
        $metrics = ['instanceId' => 'abc123'];

        $this->store->method('isReportDue')->willReturnOnConsecutiveCalls(true, false, true);
        $this->collector->method('collect')->willReturn($metrics);

        $this->store->expects($this->exactly(2))->method('save');

        $context = $this->createMock(Context::class);
        $this->extension->onBeforeReceive($context);
        $this->extension->onBeforeReceive($context);
        $this->extension->onBeforeReceive($context);
    }

    // ── MQ performance recording ──────────────────────────────────────────────

    /** @test */
    public function testMqPerformanceIsRecordedOnPostReceived(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $this->perfRecorder->expects($this->once())
            ->method('record')
            ->with(
                $this->stringStartsWith('mq://'),
                HttpPerformance::TYPE_MQ,
                $this->greaterThan(0.0),
                null
            );

        $context = $this->makeContextWithTopic('orders.import');

        $this->extension->onBeforeReceive($context);
        $this->extension->onPostReceived($context);
    }

    /** @test */
    public function testMqPerformanceUsesTopicNameFromMessageProperties(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $captured = null;
        $this->perfRecorder->method('record')
            ->willReturnCallback(function (string $path) use (&$captured) {
                $captured = $path;
            });

        $context = $this->makeContextWithTopic('orders.import');

        $this->extension->onBeforeReceive($context);
        $this->extension->onPostReceived($context);

        $this->assertSame('mq://orders.import', $captured);
    }

    /** @test */
    public function testMqPerformanceUsesUnknownWhenNoMessage(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $captured = null;
        $this->perfRecorder->method('record')
            ->willReturnCallback(function (string $path) use (&$captured) {
                $captured = $path;
            });

        $context = $this->createMock(Context::class);
        $context->method('getMessage')->willReturn(null);

        $this->extension->onBeforeReceive($context);
        $this->extension->onPostReceived($context);

        $this->assertSame('mq://unknown', $captured);
    }

    /** @test */
    public function testMqPerformanceIsNotRecordedWhenOnlyPostReceivedFires(): void
    {
        // onBeforeReceive never called → start time is 0 → no record
        $this->perfRecorder->expects($this->never())->method('record');

        $this->extension->onPostReceived($this->makeContextWithTopic('test.topic'));
    }

    /** @test */
    public function testMqResponseTimeIsPositive(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $recorded = null;
        $this->perfRecorder->method('record')
            ->willReturnCallback(function (string $path, string $type, float $ms) use (&$recorded) {
                $recorded = $ms;
            });

        $context = $this->makeContextWithTopic('slow.job');

        $this->extension->onBeforeReceive($context);
        usleep(1000); // guarantee > 0 ms
        $this->extension->onPostReceived($context);

        $this->assertGreaterThan(0.0, $recorded);
    }

    /** @test */
    public function testStartTimeIsResetAfterPostReceived(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        // First cycle: record fires once
        $this->perfRecorder->expects($this->once())->method('record');

        $context = $this->makeContextWithTopic('topic');

        $this->extension->onBeforeReceive($context);
        $this->extension->onPostReceived($context);

        // Second onPostReceived without a new onBeforeReceive: no record
        $this->extension->onPostReceived($context);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContextWithTopic(string $topic): Context
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getProperties')->willReturn([
            'oro.message_queue.client.topic_name' => $topic,
        ]);
        $message->method('getBody')->willReturn('{}');

        $context = $this->createMock(Context::class);
        $context->method('getMessage')->willReturn($message);

        return $context;
    }
}
