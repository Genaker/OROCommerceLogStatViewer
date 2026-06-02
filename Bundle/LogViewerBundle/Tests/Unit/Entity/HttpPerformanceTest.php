<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Entity;

use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use PHPUnit\Framework\TestCase;

class HttpPerformanceTest extends TestCase
{
    // ── Construction ─────────────────────────────────────────────────────────

    /** @test */
    public function testConstructorSetsAllFieldsFromFirstSample(): void
    {
        $entry = new HttpPerformance('/admin/users', HttpPerformance::TYPE_HTTP, 120.0, 200);

        $this->assertNull($entry->getId());
        $this->assertSame('/admin/users', $entry->getPath());
        $this->assertSame(HttpPerformance::TYPE_HTTP, $entry->getType());
        $this->assertSame(120.0, $entry->getAvgResponseMs());
        $this->assertSame(120.0, $entry->getMinResponseMs());
        $this->assertSame(120.0, $entry->getMaxResponseMs());
        $this->assertSame(1, $entry->getRequestCount());
        $this->assertSame(200, $entry->getLastStatusCode());
        $this->assertInstanceOf(\DateTime::class, $entry->getLastSeenAt());
    }

    /** @test */
    public function testConstructorAcceptsNullStatusCode(): void
    {
        $entry = new HttpPerformance('oro:cron', HttpPerformance::TYPE_CLI, 5000.0);

        $this->assertNull($entry->getLastStatusCode());
    }

    // ── EMA average formula ───────────────────────────────────────────────────

    /** @test */
    public function testRecordSampleAppliesEmaFormula(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0, 200);

        $entry->recordSample(200.0);

        // avg = (100 + 200) / 2 = 150
        $this->assertSame(150.0, $entry->getAvgResponseMs());
    }

    /** @test */
    public function testRecordSampleEmaConvergesOnSubsequentSamples(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0);

        $entry->recordSample(200.0); // avg = (100+200)/2 = 150
        $entry->recordSample(50.0);  // avg = (150+50)/2  = 100

        $this->assertSame(100.0, $entry->getAvgResponseMs());
    }

    // ── Min / max tracking ───────────────────────────────────────────────────

    /** @test */
    public function testRecordSampleUpdatesMinWhenLower(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 300.0);

        $entry->recordSample(50.0);

        $this->assertSame(50.0, $entry->getMinResponseMs());
        $this->assertSame(300.0, $entry->getMaxResponseMs());
    }

    /** @test */
    public function testRecordSampleUpdatesMaxWhenHigher(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0);

        $entry->recordSample(500.0);

        $this->assertSame(100.0, $entry->getMinResponseMs());
        $this->assertSame(500.0, $entry->getMaxResponseMs());
    }

    /** @test */
    public function testRecordSampleDoesNotChangeMinWhenHigher(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0);

        $entry->recordSample(150.0);

        $this->assertSame(100.0, $entry->getMinResponseMs());
    }

    /** @test */
    public function testRecordSampleDoesNotChangeMaxWhenLower(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 300.0);

        $entry->recordSample(100.0);

        $this->assertSame(300.0, $entry->getMaxResponseMs());
    }

    // ── Request count ────────────────────────────────────────────────────────

    /** @test */
    public function testRequestCountIncrementsOnEachSample(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0);

        $entry->recordSample(200.0);
        $entry->recordSample(150.0);

        $this->assertSame(3, $entry->getRequestCount());
    }

    // ── Status code ──────────────────────────────────────────────────────────

    /** @test */
    public function testRecordSampleUpdatesStatusCodeWhenProvided(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0, 200);

        $entry->recordSample(120.0, 304);

        $this->assertSame(304, $entry->getLastStatusCode());
    }

    /** @test */
    public function testRecordSampleKeepsPreviousStatusCodeWhenNullPassed(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0, 200);

        $entry->recordSample(120.0, null);

        $this->assertSame(200, $entry->getLastStatusCode());
    }

    // ── lastSeenAt ───────────────────────────────────────────────────────────

    /** @test */
    public function testLastSeenAtIsUpdatedOnEachSample(): void
    {
        $entry = new HttpPerformance('/page', HttpPerformance::TYPE_HTTP, 100.0);
        $before = $entry->getLastSeenAt();

        usleep(1000); // 1 ms
        $entry->recordSample(200.0);

        $this->assertGreaterThanOrEqual($before, $entry->getLastSeenAt());
    }

    // ── Type constants ───────────────────────────────────────────────────────

    /** @test */
    public function testTypeConstantsHaveExpectedValues(): void
    {
        $this->assertSame('http', HttpPerformance::TYPE_HTTP);
        $this->assertSame('cli', HttpPerformance::TYPE_CLI);
        $this->assertSame('mq', HttpPerformance::TYPE_MQ);
    }
}
