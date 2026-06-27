<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Genaker\Bundle\LogViewerBundle\Repository\HttpPerformanceRepository;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

#[Config]
#[ORM\Entity(repositoryClass: HttpPerformanceRepository::class)]
#[ORM\Table(name: 'genaker_http_performance')]
#[ORM\UniqueConstraint(name: 'uniq_http_perf_path_type', columns: ['path', 'type'])]
#[ORM\Index(columns: ['type'], name: 'idx_http_perf_type')]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_http_perf_last_seen')]
#[ORM\Index(columns: ['avg_response_ms'], name: 'idx_http_perf_avg')]
class HttpPerformance
{
    public const string TYPE_HTTP = 'http';
    public const string TYPE_CLI  = 'cli';
    public const string TYPE_MQ   = 'mq';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 500)]
    private string $path;

    #[ORM\Column(type: 'string', length: 10)]
    private string $type;

    /** Running EMA: new_avg = (old_avg + current) / 2 */
    #[ORM\Column(name: 'avg_response_ms', type: 'float')]
    private float $avgResponseMs;

    #[ORM\Column(name: 'min_response_ms', type: 'float')]
    private float $minResponseMs;

    #[ORM\Column(name: 'max_response_ms', type: 'float')]
    private float $maxResponseMs;

    #[ORM\Column(name: 'request_count', type: 'integer')]
    private int $requestCount;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime')]
    private \DateTime $lastSeenAt;

    #[ORM\Column(name: 'last_status_code', type: 'integer', nullable: true)]
    private ?int $lastStatusCode;

    public function __construct(string $path, string $type, float $responseMs, ?int $statusCode = null)
    {
        $this->path           = $path;
        $this->type           = $type;
        $this->avgResponseMs  = $responseMs;
        $this->minResponseMs  = $responseMs;
        $this->maxResponseMs  = $responseMs;
        $this->requestCount   = 1;
        $this->lastSeenAt     = new \DateTime();
        $this->lastStatusCode = $statusCode;
    }

    public function recordSample(float $responseMs, ?int $statusCode = null): void
    {
        $this->avgResponseMs = ($this->avgResponseMs + $responseMs) / 2.0;

        if ($responseMs < $this->minResponseMs) {
            $this->minResponseMs = $responseMs;
        }
        if ($responseMs > $this->maxResponseMs) {
            $this->maxResponseMs = $responseMs;
        }

        $this->requestCount++;
        $this->lastSeenAt = new \DateTime();

        if ($statusCode !== null) {
            $this->lastStatusCode = $statusCode;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getAvgResponseMs(): float
    {
        return $this->avgResponseMs;
    }
    public function getMinResponseMs(): float
    {
        return $this->minResponseMs;
    }
    public function getMaxResponseMs(): float
    {
        return $this->maxResponseMs;
    }
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
    public function getLastSeenAt(): \DateTime
    {
        return $this->lastSeenAt;
    }
    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }
}
