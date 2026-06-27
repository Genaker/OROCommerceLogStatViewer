<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\Repository\HttpPerformanceRepository;

/**
 * Records request/command/message performance into the genaker_http_performance table.
 *
 * Each (path, type) pair has exactly one row. On every new sample the running
 * EMA is updated: avg = (old_avg + current) / 2.  min/max track the extremes.
 * All writes are fire-and-forget; exceptions are silently swallowed so that a
 * failing DB write never affects the response.
 */
class HttpPerformanceRecorder
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly PerfDashboardConfig $config,
    ) {
    }

    /**
     * Record a single sample.  Silently ignores all errors.
     *
     * @param string   $path        Normalised path / command / MQ topic
     * @param string   $type        HttpPerformance::TYPE_*
     * @param float    $responseMs  Wall-clock duration in milliseconds
     * @param int|null $statusCode  HTTP status (null for cli/mq)
     */
    public function record(string $path, string $type, float $responseMs, ?int $statusCode = null): void
    {
        if (!$this->config->isHttpPerfEnabled()) {
            return;
        }

        if ($type === HttpPerformance::TYPE_HTTP && $statusCode !== null) {
            if (!$this->config->isStatusTracked($statusCode)) {
                return;
            }
        }

        if ($type === HttpPerformance::TYPE_CLI && !$this->config->isCliPerfEnabled()) {
            return;
        }

        if ($type === HttpPerformance::TYPE_MQ && !$this->config->isMqPerfEnabled()) {
            return;
        }

        $slowThreshold = $this->config->getHttpSlowThresholdMs();
        if ($slowThreshold > 0.0 && $responseMs < $slowThreshold) {
            return;
        }

        try {
            $em   = $this->doctrine->getManager();
            /** @var HttpPerformanceRepository $repo */
            $repo = $em->getRepository(HttpPerformance::class);

            $entry = $repo->findByPathAndType($path, $type);

            if ($entry === null) {
                $entry = new HttpPerformance($path, $type, $responseMs, $statusCode);
                $em->persist($entry);
            } else {
                $entry->recordSample($responseMs, $statusCode);
            }

            $em->flush();
        } catch (\Throwable) {
            // Performance monitoring must never break the application
        }
    }

    /**
     * Normalise a URL path: strip query string and replace numeric segments with {id}.
     *
     * /admin/user/123/edit  →  /admin/user/{id}/edit
     * /api/v1/orders/9999   →  /api/v1/orders/{id}
     */
    public static function normalizePath(string $rawPath): string
    {
        // Strip query string just in case (getPathInfo already does this, but defensive)
        $path = strtok($rawPath, '?') ?: $rawPath;

        // Replace pure-numeric path segments with {id}
        $path = preg_replace('#/\d+(?=/|$)#', '/{id}', $path);

        // Truncate to column length
        return substr($path, 0, 500);
    }
}
