<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stores and retrieves per-instance performance metric snapshots.
 *
 * Uses a PSR-6 cache pool (Redis-backed in production) for cross-instance sharing.
 * Registry key: perf_dashboard.registry  — JSON array of known instanceIds
 * Instance key: perf_dashboard.inst.<id>  — JSON snapshot per instance
 */
class PerfDashboardStore
{
    private const string KEY_REGISTRY    = 'perf_dashboard.registry';
    private const string KEY_PREFIX      = 'perf_dashboard.inst.';
    private const string KEY_RATELIMIT   = 'perf_dashboard.ratelimit.';
    private const int    REGISTRY_TTL    = 1800;
    public const int     REPORT_INTERVAL_SECONDS = 60;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache
    ) {
    }

    /**
     * Persists a metric snapshot for the given instance.
     * Also updates the central registry so other instances can discover it.
     */
    public function save(array $metrics): void
    {
        $instanceId = $metrics['instanceId'];

        $instanceItem = $this->cache->getItem(self::KEY_PREFIX . $instanceId);
        $instanceItem->set(json_encode($metrics, JSON_THROW_ON_ERROR));
        $instanceItem->expiresAfter(ServerMetricsCollector::TTL_SECONDS);
        $this->cache->save($instanceItem);

        $this->updateRegistry($instanceId);
    }

    /**
     * Returns snapshots for all known live instances (those with non-expired keys).
     *
     * @return list<array>
     */
    public function loadAll(): array
    {
        $registry = $this->loadRegistry();
        $instances = [];

        foreach ($registry as $instanceId) {
            $item = $this->cache->getItem(self::KEY_PREFIX . $instanceId);
            if (!$item->isHit()) {
                continue;
            }

            $decoded = json_decode((string) $item->get(), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $instances[] = $decoded;
            }
        }

        usort($instances, static fn (array $a, array $b) => strcmp($a['hostname'], $b['hostname']));

        return $instances;
    }

    /**
     * @return list<string>
     */
    private function loadRegistry(): array
    {
        $item = $this->cache->getItem(self::KEY_REGISTRY);
        if (!$item->isHit()) {
            return [];
        }

        $decoded = json_decode((string) $item->get(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Returns true if this instance has not reported within the rate-limit window.
     * Uses a short-TTL cache key as a lightweight distributed lock.
     */
    public function isReportDue(): bool
    {
        $hostname = (string) gethostname();
        $instanceId = substr(md5($hostname . gethostbyname($hostname)), 0, 12);
        $key = self::KEY_RATELIMIT . $instanceId;

        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            return false;
        }

        $item->set(1);
        $item->expiresAfter(self::REPORT_INTERVAL_SECONDS);
        $this->cache->save($item);

        return true;
    }

    private function updateRegistry(string $instanceId): void
    {
        $registry = $this->loadRegistry();

        if (!in_array($instanceId, $registry, true)) {
            $registry[] = $instanceId;
        }

        $registryItem = $this->cache->getItem(self::KEY_REGISTRY);
        $registryItem->set(json_encode(array_values($registry), JSON_THROW_ON_ERROR));
        $registryItem->expiresAfter(self::REGISTRY_TTL);
        $this->cache->save($registryItem);
    }
}
