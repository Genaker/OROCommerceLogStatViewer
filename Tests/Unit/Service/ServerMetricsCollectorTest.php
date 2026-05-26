<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use PHPUnit\Framework\TestCase;

class ServerMetricsCollectorTest extends TestCase
{
    private ServerMetricsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ServerMetricsCollector();
    }

    /** @test */
    public function testCollectReturnsRequiredTopLevelKeys(): void
    {
        $snapshot = $this->collector->collect();

        foreach (['instanceId', 'hostname', 'ip', 'collectedAt', 'load', 'memory', 'cpu', 'processes'] as $key) {
            $this->assertArrayHasKey($key, $snapshot, "Missing key: $key");
        }
    }

    /** @test */
    public function testInstanceIdIs12CharHexString(): void
    {
        $snapshot = $this->collector->collect();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $snapshot['instanceId']);
    }

    /** @test */
    public function testCollectedAtUsesExpectedDateFormat(): void
    {
        $snapshot = $this->collector->collect();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $snapshot['collectedAt']
        );
    }

    /** @test */
    public function testLoadContainsM1M5M15FloatValues(): void
    {
        $snapshot = $this->collector->collect();

        $this->assertArrayHasKey('m1', $snapshot['load']);
        $this->assertArrayHasKey('m5', $snapshot['load']);
        $this->assertArrayHasKey('m15', $snapshot['load']);
        $this->assertIsFloat($snapshot['load']['m1']);
        $this->assertIsFloat($snapshot['load']['m5']);
        $this->assertIsFloat($snapshot['load']['m15']);
    }

    /** @test */
    public function testMemoryContainsRequiredKeys(): void
    {
        $snapshot = $this->collector->collect();

        foreach (['total', 'free', 'available', 'used', 'usedPct'] as $key) {
            $this->assertArrayHasKey($key, $snapshot['memory'], "Missing memory key: $key");
        }
    }

    /** @test */
    public function testMemoryUsedPctIsBetweenZeroAndOneHundred(): void
    {
        $snapshot = $this->collector->collect();
        $pct      = $snapshot['memory']['usedPct'];

        $this->assertGreaterThanOrEqual(0.0, $pct);
        $this->assertLessThanOrEqual(100.0, $pct);
    }

    /** @test */
    public function testCpuCoresIsAtLeastOne(): void
    {
        $snapshot = $this->collector->collect();

        $this->assertArrayHasKey('cores', $snapshot['cpu']);
        $this->assertGreaterThanOrEqual(1, $snapshot['cpu']['cores']);
    }

    /** @test */
    public function testProcessesIsAnArray(): void
    {
        $snapshot = $this->collector->collect();

        $this->assertIsArray($snapshot['processes']);
    }

    /** @test */
    public function testProcessEntriesHaveRequiredFields(): void
    {
        $snapshot = $this->collector->collect();

        foreach ($snapshot['processes'] as $process) {
            foreach (['pid', 'user', 'cpu', 'mem', 'command'] as $field) {
                $this->assertArrayHasKey($field, $process, "Missing process field: $field");
            }
        }
    }

    /** @test */
    public function testProcessesRespectTopCountLimit(): void
    {
        $snapshot = $this->collector->collect();

        $this->assertLessThanOrEqual(ServerMetricsCollector::TOP_PROCESS_COUNT, count($snapshot['processes']));
    }

    /** @test */
    public function testInstanceIdIsStableForSameHostname(): void
    {
        $first  = $this->collector->collect();
        $second = $this->collector->collect();

        $this->assertSame($first['instanceId'], $second['instanceId']);
        $this->assertSame($first['hostname'], $second['hostname']);
    }

    /** @test */
    public function testTtlSecondsConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, ServerMetricsCollector::TTL_SECONDS);
    }
}
