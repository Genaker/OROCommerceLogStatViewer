<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Genaker\Bundle\LogViewerBundle\Repository\SqlIssueRepository;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

/**
 * Tracks N+1 and slow-query issues per normalised SQL template.
 *
 * One row per unique template. Upserted via raw DBAL on kernel.terminate.
 * worst_* fields and last_* fields are updated only when a new worst value
 * is detected, so they always reflect the worst observed occurrence.
 */
#[Config]
#[ORM\Entity(repositoryClass: SqlIssueRepository::class)]
#[ORM\Table(name: 'genaker_sql_issue')]
#[ORM\UniqueConstraint(name: 'uniq_sql_issue_template', columns: ['sql_template'])]
#[ORM\Index(columns: ['is_n1'], name: 'idx_sql_issue_n1')]
#[ORM\Index(columns: ['is_slow'], name: 'idx_sql_issue_slow')]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_sql_issue_last_seen')]
#[ORM\Index(columns: ['worst_slow_ms'], name: 'idx_sql_issue_worst_slow')]
class SqlIssue
{
    public const string COLUMN_ID              = 'id';
    public const string COLUMN_SQL_TEMPLATE    = 'sql_template';
    public const string COLUMN_IS_N1           = 'is_n1';
    public const string COLUMN_IS_SLOW         = 'is_slow';
    public const string COLUMN_WORST_N1_COUNT  = 'worst_n1_count';
    public const string COLUMN_WORST_SLOW_MS   = 'worst_slow_ms';
    public const string COLUMN_OCCURRENCE_COUNT = 'occurrence_count';
    public const string COLUMN_LAST_SEEN_AT    = 'last_seen_at';
    public const string COLUMN_LAST_CALLER     = 'last_caller';
    public const string COLUMN_LAST_PARAMS     = 'last_params';
    public const string COLUMN_LAST_URL        = 'last_url';
    public const string COLUMN_SUGGESTION      = 'suggestion';
    public const string COLUMN_ANALYSIS_DATA   = 'analysis_data';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'sql_template', type: 'text')]
    private string $sqlTemplate;

    #[ORM\Column(name: 'is_n1', type: 'boolean')]
    private bool $isN1;

    #[ORM\Column(name: 'is_slow', type: 'boolean')]
    private bool $isSlow;

    #[ORM\Column(name: 'worst_n1_count', type: 'integer', nullable: true)]
    private ?int $worstN1Count;

    #[ORM\Column(name: 'worst_slow_ms', type: 'float', nullable: true)]
    private ?float $worstSlowMs;

    #[ORM\Column(name: 'occurrence_count', type: 'integer')]
    private int $occurrenceCount;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime')]
    private \DateTime $lastSeenAt;

    #[ORM\Column(name: 'last_caller', type: 'string', length: 500, nullable: true)]
    private ?string $lastCaller;

    /** @var array<int|string, mixed>|null */
    #[ORM\Column(name: 'last_params', type: 'json', nullable: true)]
    private ?array $lastParams;

    #[ORM\Column(name: 'last_url', type: 'string', length: 1000, nullable: true)]
    private ?string $lastUrl;

    #[ORM\Column(name: 'suggestion', type: 'text', nullable: true)]
    private ?string $suggestion;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'analysis_data', type: 'json', nullable: true)]
    private ?array $analysisData;

    public function __construct(
        string $sqlTemplate,
        bool $isN1,
        bool $isSlow,
        ?int $worstN1Count,
        ?float $worstSlowMs,
        ?string $caller,
        ?array $params,
        ?string $url,
        ?string $suggestion = null,
        ?array $analysisData = null,
    ) {
        $this->sqlTemplate     = $sqlTemplate;
        $this->isN1            = $isN1;
        $this->isSlow          = $isSlow;
        $this->worstN1Count    = $worstN1Count;
        $this->worstSlowMs     = $worstSlowMs;
        $this->occurrenceCount = 1;
        $this->lastSeenAt      = new \DateTime();
        $this->lastCaller      = $caller;
        $this->lastParams      = $params;
        $this->lastUrl         = $url;
        $this->suggestion      = $suggestion;
        $this->analysisData    = $analysisData;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSqlTemplate(): string
    {
        return $this->sqlTemplate;
    }
    public function isN1(): bool
    {
        return $this->isN1;
    }
    public function isSlow(): bool
    {
        return $this->isSlow;
    }
    public function getWorstN1Count(): ?int
    {
        return $this->worstN1Count;
    }
    public function getWorstSlowMs(): ?float
    {
        return $this->worstSlowMs;
    }
    public function getOccurrenceCount(): int
    {
        return $this->occurrenceCount;
    }
    public function getLastSeenAt(): \DateTime
    {
        return $this->lastSeenAt;
    }
    public function getLastCaller(): ?string
    {
        return $this->lastCaller;
    }
    public function getLastParams(): ?array
    {
        return $this->lastParams;
    }
    public function getLastUrl(): ?string
    {
        return $this->lastUrl;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function getAnalysisData(): ?array
    {
        return $this->analysisData;
    }
}
