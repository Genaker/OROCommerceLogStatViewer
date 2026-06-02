<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\LoggerChain;
use Genaker\Bundle\LogViewerBundle\EventListener\SqlPerformanceListener;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\SqlPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\SqlQueryCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @covers \Genaker\Bundle\LogViewerBundle\EventListener\SqlPerformanceListener
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class SqlPerformanceListenerTest extends TestCase
{
    private SqlQueryCollector&MockObject $collector;
    private SqlPerformanceRecorder&MockObject $recorder;
    private Connection&MockObject $connection;
    private Configuration&MockObject $dbalConfig;
    private PerfDashboardConfig&MockObject $config;
    private SqlPerformanceListener $listener;

    protected function setUp(): void
    {
        $this->collector  = $this->createMock(SqlQueryCollector::class);
        $this->recorder   = $this->createMock(SqlPerformanceRecorder::class);
        $this->dbalConfig = $this->createMock(Configuration::class);
        $this->connection = $this->createMock(Connection::class);
        $this->config     = $this->createMock(PerfDashboardConfig::class);

        $this->connection->method('getConfiguration')->willReturn($this->dbalConfig);

        $this->listener = new SqlPerformanceListener(
            $this->collector,
            $this->recorder,
            $this->connection,
            $this->config
        );
    }

    public function testOnKernelRequestSubRequestDoesNotChainCollector(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->dbalConfig->expects(self::never())->method('setSQLLogger');

        $event = $this->makeRequestEvent(false);
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestMainRequestChainsCollector(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->dbalConfig->method('getSQLLogger')->willReturn(null);
        $this->dbalConfig->expects(self::once())->method('setSQLLogger');

        $event = $this->makeRequestEvent(true);
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestIsIdempotent(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->dbalConfig->method('getSQLLogger')->willReturn(null);
        $this->dbalConfig->expects(self::once())->method('setSQLLogger');

        $event = $this->makeRequestEvent(true);
        $this->listener->onKernelRequest($event);
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestDoesNotChainWhenDisabled(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(false);
        $this->dbalConfig->expects(self::never())->method('setSQLLogger');

        $event = $this->makeRequestEvent(true);
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelTerminateCallsFlushWithUrl(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->config->method('getSqlN1Threshold')->willReturn(5);
        $this->config->method('getSqlSlowThresholdMs')->willReturn(10.0);

        $issues = [['template' => 'SELECT 1', 'isN1' => true, 'isSlow' => false,
                     'worstN1Count' => 6, 'worstSlowMs' => null,
                     'caller' => null, 'params' => null, 'url' => '/admin/orders?page=2']];

        $this->collector->method('getIssues')
            ->with('/admin/orders?page=2', 5, 10.0)
            ->willReturn($issues);

        $this->recorder->expects(self::once())->method('flush')->with($issues);

        $request = Request::create('/admin/orders', 'GET', ['page' => '2']);
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $event   = new TerminateEvent($kernel, $request, new Response());

        $this->listener->onKernelTerminate($event);
    }

    public function testOnKernelTerminateUrlHasNoQueryStringWhenEmpty(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->config->method('getSqlN1Threshold')->willReturn(5);
        $this->config->method('getSqlSlowThresholdMs')->willReturn(10.0);

        $this->collector->method('getIssues')
            ->with('/admin/orders', 5, 10.0)
            ->willReturn([]);

        $this->recorder->expects(self::once())->method('flush')->with([]);

        $request = Request::create('/admin/orders');
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $event   = new TerminateEvent($kernel, $request, new Response());

        $this->listener->onKernelTerminate($event);
    }

    public function testOnConsoleTerminateCallsFlushWithCliPrefix(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->config->method('getSqlN1Threshold')->willReturn(5);
        $this->config->method('getSqlSlowThresholdMs')->willReturn(10.0);
        $this->dbalConfig->method('getSQLLogger')->willReturn(null);
        $this->dbalConfig->method('setSQLLogger');

        $this->collector->method('getIssues')
            ->with('cli:egerdau:invoices:import', 5, 10.0)
            ->willReturn([]);

        $this->recorder->expects(self::once())->method('flush')->with([]);

        $command = new Command('egerdau:invoices:import');
        $event   = new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0);

        $this->listener->onConsoleTerminate($event);
    }

    private function makeRequestEvent(bool $isMainRequest): RequestEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin/test');
        $type    = $isMainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST;

        return new RequestEvent($kernel, $request, $type);
    }
}
