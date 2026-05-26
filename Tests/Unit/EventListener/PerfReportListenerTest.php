<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Genaker\Bundle\LogViewerBundle\EventListener\PerfReportListener;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PerfReportListenerTest extends TestCase
{
    private ServerMetricsCollector $collector;
    private PerfDashboardStore $store;
    private PerfReportListener $listener;

    protected function setUp(): void
    {
        $this->collector = $this->createMock(ServerMetricsCollector::class);
        $this->store     = $this->createMock(PerfDashboardStore::class);
        $this->listener  = new PerfReportListener($this->collector, $this->store);
    }

    /** @test */
    public function testOnKernelTerminateSkipsWhenReportIsNotDue(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $this->collector->expects($this->never())->method('collect');
        $this->store->expects($this->never())->method('save');

        $this->listener->onKernelTerminate($this->makeEvent());
    }

    /** @test */
    public function testOnKernelTerminateCollectsAndSavesWhenReportIsDue(): void
    {
        $metrics = ['instanceId' => 'abc123', 'hostname' => 'web-01'];

        $this->store->method('isReportDue')->willReturn(true);
        $this->collector->expects($this->once())->method('collect')->willReturn($metrics);
        $this->store->expects($this->once())->method('save')->with($metrics);

        $this->listener->onKernelTerminate($this->makeEvent());
    }

    private function makeEvent(): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new TerminateEvent($kernel, new Request(), new Response());
    }
}
