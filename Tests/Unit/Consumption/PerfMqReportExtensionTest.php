<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Consumption;

use Genaker\Bundle\LogViewerBundle\Consumption\PerfMqReportExtension;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use Oro\Component\MessageQueue\Consumption\Context;
use PHPUnit\Framework\TestCase;

class PerfMqReportExtensionTest extends TestCase
{
    private ServerMetricsCollector $collector;
    private PerfDashboardStore $store;
    private PerfMqReportExtension $extension;

    protected function setUp(): void
    {
        $this->collector = $this->createMock(ServerMetricsCollector::class);
        $this->store     = $this->createMock(PerfDashboardStore::class);
        $this->extension = new PerfMqReportExtension($this->collector, $this->store);
    }

    /** @test */
    public function testOnBeforeReceiveSkipsWhenReportIsNotDue(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $this->collector->expects($this->never())->method('collect');
        $this->store->expects($this->never())->method('save');

        $this->extension->onBeforeReceive($this->createMock(Context::class));
    }

    /** @test */
    public function testOnBeforeReceiveCollectsAndSavesWhenReportIsDue(): void
    {
        $metrics = ['instanceId' => 'abc123', 'hostname' => 'mq-worker-01'];

        $this->store->method('isReportDue')->willReturn(true);
        $this->collector->expects($this->once())->method('collect')->willReturn($metrics);
        $this->store->expects($this->once())->method('save')->with($metrics);

        $this->extension->onBeforeReceive($this->createMock(Context::class));
    }

    /** @test */
    public function testOnBeforeReceiveIsCalledOnEveryLoopIteration(): void
    {
        $metrics = ['instanceId' => 'abc123', 'hostname' => 'mq-worker-01'];

        $this->store->method('isReportDue')->willReturnOnConsecutiveCalls(true, false, true);
        $this->collector->method('collect')->willReturn($metrics);

        $this->store->expects($this->exactly(2))->method('save');

        $context = $this->createMock(Context::class);
        $this->extension->onBeforeReceive($context);
        $this->extension->onBeforeReceive($context);
        $this->extension->onBeforeReceive($context);
    }
}
