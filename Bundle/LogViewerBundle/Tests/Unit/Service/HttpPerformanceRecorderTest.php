<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\Repository\HttpPerformanceRepository;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpPerformanceRecorderTest extends TestCase
{
    private ManagerRegistry&MockObject $doctrine;
    private EntityManagerInterface&MockObject $em;
    private HttpPerformanceRepository&MockObject $repo;
    private PerfDashboardConfig&MockObject $config;
    private HttpPerformanceRecorder $recorder;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(HttpPerformanceRepository::class);
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturn($this->repo);
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->doctrine->method('getManager')->willReturn($this->em);

        $this->config   = $this->createMock(PerfDashboardConfig::class);
        $this->config->method('isHttpPerfEnabled')->willReturn(true);
        $this->config->method('isStatusTracked')->willReturn(true);
        $this->config->method('isCliPerfEnabled')->willReturn(true);
        $this->config->method('isMqPerfEnabled')->willReturn(true);

        $this->recorder = new HttpPerformanceRecorder($this->doctrine, $this->config);
    }

    // ── Feature disabled ─────────────────────────────────────────────────────

    /** @test */
    public function testRecordDoesNothingWhenFeatureIsDisabled(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isHttpPerfEnabled')->willReturn(false);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $recorder = new HttpPerformanceRecorder($doctrine, $config);
        $recorder->record('/page', HttpPerformance::TYPE_HTTP, 100.0, 200);
    }

    /** @test */
    public function testRecordSkipsHttpWhenStatusCodeNotTracked(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isHttpPerfEnabled')->willReturn(true);
        $config->method('isStatusTracked')->with(404)->willReturn(false);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $recorder = new HttpPerformanceRecorder($doctrine, $config);
        $recorder->record('/not-found', HttpPerformance::TYPE_HTTP, 5.0, 404);
    }

    /** @test */
    public function testRecordSkipsCliWhenCliTrackingDisabled(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isHttpPerfEnabled')->willReturn(true);
        $config->method('isCliPerfEnabled')->willReturn(false);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $recorder = new HttpPerformanceRecorder($doctrine, $config);
        $recorder->record('oro:cron', HttpPerformance::TYPE_CLI, 1000.0);
    }

    /** @test */
    public function testRecordSkipsMqWhenMqTrackingDisabled(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isHttpPerfEnabled')->willReturn(true);
        $config->method('isMqPerfEnabled')->willReturn(false);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $recorder = new HttpPerformanceRecorder($doctrine, $config);
        $recorder->record('mq://orders.import', HttpPerformance::TYPE_MQ, 800.0);
    }

    // ── New entry ────────────────────────────────────────────────────────────

    /** @test */
    public function testRecordPersistsNewEntityWhenPathNotFound(): void
    {
        $this->repo->method('findByPathAndType')->willReturn(null);

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(HttpPerformance::class));
        $this->em->expects($this->once())->method('flush');

        $this->recorder->record('/admin/users', HttpPerformance::TYPE_HTTP, 250.0, 200);
    }

    /** @test */
    public function testNewEntityHasCorrectInitialValues(): void
    {
        $captured = null;
        $this->repo->method('findByPathAndType')->willReturn(null);
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$captured) {
            $captured = $entity;
        });

        $this->recorder->record('/admin/users', HttpPerformance::TYPE_HTTP, 250.0, 200);

        $this->assertInstanceOf(HttpPerformance::class, $captured);
        $this->assertSame('/admin/users', $captured->getPath());
        $this->assertSame(HttpPerformance::TYPE_HTTP, $captured->getType());
        $this->assertSame(250.0, $captured->getAvgResponseMs());
        $this->assertSame(200, $captured->getLastStatusCode());
    }

    // ── Existing entry ───────────────────────────────────────────────────────

    /** @test */
    public function testRecordCallsRecordSampleOnExistingEntity(): void
    {
        $existing = new HttpPerformance('/admin/users', HttpPerformance::TYPE_HTTP, 100.0, 200);
        $this->repo->method('findByPathAndType')->willReturn($existing);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->recorder->record('/admin/users', HttpPerformance::TYPE_HTTP, 300.0, 200);

        // avg = (100 + 300) / 2 = 200
        $this->assertSame(200.0, $existing->getAvgResponseMs());
        $this->assertSame(2, $existing->getRequestCount());
    }

    // ── Exception swallowing ─────────────────────────────────────────────────

    /** @test */
    public function testRecordSwallowsDoctrineExceptions(): void
    {
        $this->doctrine->method('getManager')->willThrowException(new \RuntimeException('DB down'));

        // Must not throw
        $this->recorder->record('/page', HttpPerformance::TYPE_HTTP, 100.0, 200);
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function testRecordSwallowsFlushExceptions(): void
    {
        $this->repo->method('findByPathAndType')->willReturn(null);
        $this->em->method('flush')->willThrowException(new \RuntimeException('Flush failed'));

        // Must not throw
        $this->recorder->record('/page', HttpPerformance::TYPE_HTTP, 100.0, 200);
        $this->addToAssertionCount(1);
    }

    // ── Path normalisation ───────────────────────────────────────────────────

    /** @test */
    public function testNormalizePathReplacesNumericSegments(): void
    {
        $this->assertSame(
            '/admin/user/{id}/edit',
            HttpPerformanceRecorder::normalizePath('/admin/user/123/edit')
        );
    }

    /** @test */
    public function testNormalizePathReplacesMultipleNumericSegments(): void
    {
        $this->assertSame(
            '/api/orders/{id}/items/{id}',
            HttpPerformanceRecorder::normalizePath('/api/orders/9999/items/42')
        );
    }

    /** @test */
    public function testNormalizePathLeavesNonNumericSegmentsIntact(): void
    {
        $this->assertSame(
            '/admin/dashboard',
            HttpPerformanceRecorder::normalizePath('/admin/dashboard')
        );
    }

    /** @test */
    public function testNormalizePathStripsQueryString(): void
    {
        $this->assertSame(
            '/search',
            HttpPerformanceRecorder::normalizePath('/search?q=term&page=2')
        );
    }

    /** @test */
    public function testNormalizePathStripsQueryStringBeforeReplacingIds(): void
    {
        $this->assertSame(
            '/items/{id}',
            HttpPerformanceRecorder::normalizePath('/items/5?foo=bar')
        );
    }

    /** @test */
    public function testNormalizePathTruncatesToFiveHundredChars(): void
    {
        $longPath = '/' . str_repeat('a', 600);
        $result   = HttpPerformanceRecorder::normalizePath($longPath);

        $this->assertSame(500, strlen($result));
    }

    /** @test */
    public function testNormalizePathHandlesRootPath(): void
    {
        $this->assertSame('/', HttpPerformanceRecorder::normalizePath('/'));
    }

    /** @test */
    public function testNormalizePathDoesNotReplaceAlphanumericSegments(): void
    {
        $this->assertSame(
            '/admin/oro_user/create',
            HttpPerformanceRecorder::normalizePath('/admin/oro_user/create')
        );
    }

    // ── HTTP slow threshold ──────────────────────────────────────────────────

    /** @test */
    public function testRecordLogsAllWhenSlowThresholdIsZero(): void
    {
        $this->config->method('getHttpSlowThresholdMs')->willReturn(0.0);
        $this->repo->method('findByPathAndType')->willReturn(null);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $this->recorder->record('/fast', HttpPerformance::TYPE_HTTP, 5.0, 200);
    }

    /** @test */
    public function testRecordSkipsRequestFasterThanSlowThreshold(): void
    {
        $this->config->method('getHttpSlowThresholdMs')->willReturn(500.0);
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $this->recorder->record('/fast', HttpPerformance::TYPE_HTTP, 200.0, 200);
    }

    /** @test */
    public function testRecordLogsRequestSlowerThanSlowThreshold(): void
    {
        $this->config->method('getHttpSlowThresholdMs')->willReturn(500.0);
        $this->repo->method('findByPathAndType')->willReturn(null);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $this->recorder->record('/slow', HttpPerformance::TYPE_HTTP, 600.0, 200);
    }
}
