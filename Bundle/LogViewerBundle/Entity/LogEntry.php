<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;

#[Config]
#[ORM\Entity]
#[ORM\Table(name: 'genaker_log_entry')]
#[ORM\UniqueConstraint(name: 'uniq_log_entry_message_key', columns: ['message_key'])]
#[ORM\Index(columns: ['channel'], name: 'idx_log_entry_channel')]
#[ORM\Index(columns: ['level'], name: 'idx_log_entry_level')]
#[ORM\Index(columns: ['created_at'], name: 'idx_log_entry_created')]
class LogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $channel;

    #[ORM\Column(type: 'smallint')]
    private int $level;

    #[ORM\Column(name: 'level_name', type: 'string', length: 20)]
    private string $levelName;

    #[ORM\Column(type: 'text')]
    private string $message;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $extra;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(name: 'url', type: 'string', length: 2000, nullable: true)]
    private ?string $url;

    #[ORM\Column(name: 'ip', type: 'string', length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(name: 'message_key', type: 'string', length: 64, nullable: true)]
    private ?string $messageKey;

    #[ORM\Column(name: 'occurrence_count', type: 'integer', options: ['default' => 1])]
    private int $occurrenceCount = 1;

    #[ORM\Column(name: 'first_seen_at', type: 'datetime', nullable: true)]
    private ?\DateTime $firstSeenAt;

    public function __construct(
        string $channel,
        int $level,
        string $levelName,
        string $message,
        ?array $context = null,
        ?array $extra = null,
        ?string $url = null,
        ?string $ip = null,
    ) {
        $this->channel         = $channel;
        $this->level           = $level;
        $this->levelName       = $levelName;
        $this->message         = $message;
        $this->context         = $context;
        $this->extra           = $extra;
        $this->createdAt       = new \DateTime();
        $this->url             = $url;
        $this->ip              = $ip;
        $this->messageKey      = null;
        $this->occurrenceCount = 1;
        $this->firstSeenAt     = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getChannel(): string { return $this->channel; }
    public function getLevel(): int { return $this->level; }
    public function getLevelName(): string { return $this->levelName; }
    public function getMessage(): string { return $this->message; }
    public function getContext(): ?array { return $this->context; }
    public function getExtra(): ?array { return $this->extra; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUrl(): ?string { return $this->url; }
    public function getIp(): ?string { return $this->ip; }
    public function getMessageKey(): ?string { return $this->messageKey; }
    public function getOccurrenceCount(): int { return $this->occurrenceCount; }
    public function getFirstSeenAt(): ?\DateTime { return $this->firstSeenAt; }
}
