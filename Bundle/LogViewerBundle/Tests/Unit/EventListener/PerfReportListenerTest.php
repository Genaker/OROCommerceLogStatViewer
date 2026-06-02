<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\EventListener\PerfReportListener;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PerfReportListenerTest extends TestCase
{
    private ServerMetricsCollector&MockObject $collector;
    private PerfDashboardStore&MockObject $store;
    private PerfDashboardConfig&MockObject $config;
    private HttpPerformanceRecorder&MockObject $perfRecorder;
    private PerfReportListener $listener;

    protected function setUp(): void
    {
        $this->collector    = $this->createMock(ServerMetricsCollector::class);
        $this->store        = $this->createMock(PerfDashboardStore::class);
        $this->config       = $this->createMock(PerfDashboardConfig::class);
        $this->perfRecorder = $this->createMock(HttpPerformanceRecorder::class);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isHttpReportingEnabled')->willReturn(true);

        $this->listener = new PerfReportListener(
            $this->collector,
            $this->store,
            $this->config,
            $this->perfRecorder
        );
    }

    // ── Server-metrics reporting ──────────────────────────────────────────────

    /** @test */
    public function testServerMetricsAreSkippedWhenReportIsNotDue(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $this->collector->expects($this->never())->method('collect');
        $this->store->expects($this->never())->method('save');

        $this->listener->onKernelTerminate($this->makeEvent());
    }

    /** @test */
    public function testServerMetricsAreCollectedAndSavedWhenReportIsDue(): void
    {
        $metrics = ['instanceId' => 'abc123', 'hostname' => 'web-01'];

        $this->store->method('isReportDue')->willReturn(true);
        $this->collector->expects($this->once())->method('collect')->willReturn($metrics);
        $this->store->expects($this->once())->method('save')->with($metrics);

        $this->listener->onKernelTerminate($this->makeEvent());
    }

    /** @test */
    public function testServerMetricsAreSkippedWhenDashboardIsDisabled(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $config->method('isHttpReportingEnabled')->willReturn(true);

        $collector = $this->createMock(ServerMetricsCollector::class);
        $collector->expects($this->never())->method('collect');

        $store = $this->createMock(PerfDashboardStore::class);
        $store->method('isReportDue')->willReturn(true);

        $listener = new PerfReportListener($collector, $store, $config, $this->perfRecorder);
        $listener->onKernelTerminate($this->makeEvent());
    }

    /** @test */
    public function testServerMetricsAreSkippedWhenHttpReportingIsDisabled(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isHttpReportingEnabled')->willReturn(false);

        $collector = $this->createMock(ServerMetricsCollector::class);
        $collector->expects($this->never())->method('collect');

        $store = $this->createMock(PerfDashboardStore::class);
        $store->method('isReportDue')->willReturn(true);

        $listener = new PerfReportListener($collector, $store, $config, $this->perfRecorder);
        $listener->onKernelTerminate($this->makeEvent());
    }

    // ── HTTP performance recording ────────────────────────────────────────────

    /** @test */
    public function testHttpPerformanceIsRecordedOnEveryTerminateEvent(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $this->perfRecorder->expects($this->once())
            ->method('record')
            ->with(
                $this->isType('string'),   // normalised path
                HttpPerformance::TYPE_HTTP,
                $this->greaterThan(0.0),   // responseMs
                200                        // status code
            );

        $this->listener->onKernelTerminate($this->makeEvent(statusCode: 200));
    }

    /** @test */
    public function testHttpPerformancePassesCorrectPath(): void
    {
        $this->store->method('isReportDue')->willReturn(false);

        $captured = [];
        $this->perfRecorder->method('record')
            ->willReturnCallback(function (string $path, string $type, float $ms, ?int $code) use (&$captured) {
                $captured = ['path' => $path, 'type' => $type, 'code' => $code];
            });

        $request = Request::create('/admin/users/42/edit');
        $this->listener->onKernelTerminate($this->makeEventForRequest($request, 200));

        // Numeric segment 42 should be replaced by {id}
        $this->assertSame('/admin/users/{id}/edit', $captured['path']);
        $this->assertSame(HttpPerformance::TYPE_HTTP, $captured['type']);
        $this->assertSame(200, $captured['code']);
    }

    /** @test */
    public function testHttpPerformanceIsRecordedEvenWhenServerMetricsAreDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->store->method('isReportDue')->willReturn(false);

        $this->perfRecorder->expects($this->once())->method('record');

        $this->listener->onKernelTerminate($this->makeEvent());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEvent(int $statusCode = 200): TerminateEvent
    {
        return $this->makeEventForRequest(new Request(), $statusCode);
    }

    private function makeEventForRequest(Request $request, int $statusCode): TerminateEvent
    {
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $response = new Response('', $statusCode);

        return new TerminateEvent($kernel, $request, $response);
    }
}
