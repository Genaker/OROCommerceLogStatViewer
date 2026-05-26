<?php

// phpcs:ignoreFile
// @SuppressWarnings(PHPMD.TooManyPublicMethods)

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Genaker\Bundle\LogViewerBundle\Service\ServerMetricsCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class PerfDashboardStoreTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;
    private PerfDashboardStore $store;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->store = new PerfDashboardStore($this->cache);
    }

    // ── save() ─────────────────────────────────────────────────────────────

    /** @test */
    public function testSavePersistsInstanceSnapshotAndUpdatesRegistry(): void
    {
        $metrics = $this->makeMetrics('abc123');

        $instanceItem  = $this->makeCacheItem(false);
        $registryItem  = $this->makeCacheItem(false);

        // getItem called 3 times: inst key + loadRegistry + updateRegistry
        $this->cache->expects($this->exactly(3))
            ->method('getItem')
            ->willReturnCallback(function (string $key) use ($instanceItem, $registryItem): CacheItemInterface {
                if (str_starts_with($key, 'perf_dashboard.inst.')) {
                    return $instanceItem;
                }

                return $registryItem;
            });

        $instanceItem->expects($this->once())
            ->method('set')
            ->with($this->callback(fn ($v) => str_contains($v, '"abc123"')));

        $instanceItem->expects($this->once())
            ->method('expiresAfter')
            ->with(ServerMetricsCollector::TTL_SECONDS);

        $this->cache->expects($this->exactly(2))
            ->method('save');

        $this->store->save($metrics);
    }

    /** @test */
    public function testSaveAddsInstanceIdToRegistryWhenNotAlreadyPresent(): void
    {
        $metrics = $this->makeMetrics('newid');

        $instanceItem = $this->makeCacheItem(false);

        $existingRegistryItem = $this->makeCacheItem(true, json_encode(['existingid']));
        $newRegistryItem      = $this->makeCacheItem(false);

        $callCount = 0;
        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key) use (
                $instanceItem,
                $existingRegistryItem,
                $newRegistryItem,
                &$callCount
            ): CacheItemInterface {
                if (str_starts_with($key, 'perf_dashboard.inst.')) {
                    return $instanceItem;
                }
                // First registry call is loadRegistry(), second is updateRegistry()
                $callCount++;

                return $callCount === 1 ? $existingRegistryItem : $newRegistryItem;
            });

        $newRegistryItem->expects($this->once())
            ->method('set')
            ->with($this->callback(function (string $json): bool {
                $decoded = json_decode($json, true);

                return in_array('existingid', $decoded, true) && in_array('newid', $decoded, true);
            }));

        $this->cache->method('save');

        $this->store->save($metrics);
    }

    /** @test */
    public function testSaveDoesNotDuplicateExistingInstanceIdInRegistry(): void
    {
        $metrics = $this->makeMetrics('abc123');

        $instanceItem         = $this->makeCacheItem(false);
        $existingRegistryItem = $this->makeCacheItem(true, json_encode(['abc123']));
        $newRegistryItem      = $this->makeCacheItem(false);

        $callCount = 0;
        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key) use (
                $instanceItem,
                $existingRegistryItem,
                $newRegistryItem,
                &$callCount
            ): CacheItemInterface {
                if (str_starts_with($key, 'perf_dashboard.inst.')) {
                    return $instanceItem;
                }
                $callCount++;

                return $callCount === 1 ? $existingRegistryItem : $newRegistryItem;
            });

        $newRegistryItem->expects($this->once())
            ->method('set')
            ->with($this->callback(function (string $json): bool {
                $decoded = json_decode($json, true);

                return count($decoded) === 1 && $decoded[0] === 'abc123';
            }));

        $this->cache->method('save');

        $this->store->save($metrics);
    }

    // ── loadAll() ──────────────────────────────────────────────────────────

    /** @test */
    public function testLoadAllReturnsEmptyArrayWhenRegistryMissing(): void
    {
        $registryItem = $this->makeCacheItem(false);
        $this->cache->method('getItem')->willReturn($registryItem);

        $this->assertSame([], $this->store->loadAll());
    }

    /** @test */
    public function testLoadAllSkipsExpiredInstanceKeys(): void
    {
        $registryItem = $this->makeCacheItem(true, json_encode(['id1', 'id2']));
        $expiredItem  = $this->makeCacheItem(false);

        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key) use ($registryItem, $expiredItem): CacheItemInterface {
                return str_ends_with($key, 'registry') ? $registryItem : $expiredItem;
            });

        $this->assertSame([], $this->store->loadAll());
    }

    /** @test */
    public function testLoadAllReturnsSortedInstancesByHostname(): void
    {
        $snapshotB = $this->makeMetrics('id2', 'beta-host');
        $snapshotA = $this->makeMetrics('id1', 'alpha-host');

        $registryItem = $this->makeCacheItem(true, json_encode(['id2', 'id1']));
        $itemB        = $this->makeCacheItem(true, json_encode($snapshotB));
        $itemA        = $this->makeCacheItem(true, json_encode($snapshotA));

        $this->cache->method('getItem')
            ->willReturnCallback(
                function (string $key) use ($registryItem, $itemA, $itemB): CacheItemInterface {
                    if (str_ends_with($key, 'registry')) {
                        return $registryItem;
                    }

                    return str_ends_with($key, 'id1') ? $itemA : $itemB;
                }
            );

        $result = $this->store->loadAll();

        $this->assertCount(2, $result);
        $this->assertSame('alpha-host', $result[0]['hostname']);
        $this->assertSame('beta-host', $result[1]['hostname']);
    }

    /** @test */
    public function testLoadAllIgnoresNonArrayCacheValues(): void
    {
        $registryItem  = $this->makeCacheItem(true, json_encode(['id1']));
        $corruptedItem = $this->makeCacheItem(true, '"not-an-array"');

        $this->cache->method('getItem')
            ->willReturnCallback(
                function (string $key) use ($registryItem, $corruptedItem): CacheItemInterface {
                    return str_ends_with($key, 'registry') ? $registryItem : $corruptedItem;
                }
            );

        $this->assertSame([], $this->store->loadAll());
    }

    // ── isReportDue() ──────────────────────────────────────────────────────

    /** @test */
    public function testIsReportDueReturnsFalseWhenRateLimitKeyIsHit(): void
    {
        $rateLimitItem = $this->makeCacheItem(true);
        $this->cache->method('getItem')->willReturn($rateLimitItem);

        $this->assertFalse($this->store->isReportDue());
    }

    /** @test */
    public function testIsReportDueReturnsTrueAndSetsRateLimitKeyWhenNotHit(): void
    {
        $rateLimitItem = $this->makeCacheItem(false);

        $rateLimitItem->expects($this->once())->method('set')->with(1);
        $rateLimitItem->expects($this->once())
            ->method('expiresAfter')
            ->with(PerfDashboardStore::REPORT_INTERVAL_SECONDS);

        $this->cache->method('getItem')->willReturn($rateLimitItem);
        $this->cache->expects($this->once())->method('save')->with($rateLimitItem);

        $this->assertTrue($this->store->isReportDue());
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function makeCacheItem(bool $isHit, ?string $value = null): CacheItemInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn($isHit);
        $item->method('get')->willReturn($value);

        return $item;
    }

    private function makeMetrics(string $instanceId, string $hostname = 'web-01'): array
    {
        return [
            'instanceId'  => $instanceId,
            'hostname'    => $hostname,
            'ip'          => '10.0.0.1',
            'collectedAt' => '2026-05-26 12:00:00',
            'load'        => ['m1' => 0.5, 'm5' => 0.4, 'm15' => 0.3],
            'memory'      => ['total' => 8000000, 'free' => 2000000, 'available' => 3000000, 'used' => 5000000, 'usedPct' => 62.5],
            'cpu'         => ['cores' => 4],
            'processes'   => [],
        ];
    }
}
