<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Handler;

use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Monolog handler that writes log entries to the genaker_log_entry table.
 *
 * Supports two write modes:
 *  - "deferred" (default): buffers records in memory, flushed on kernel.terminate
 *  - "immediate": writes each record to DB as it arrives
 *
 * Auto-truncation: after flush/write, periodically checks if the table exceeds
 * the configured max size (MB) and deletes oldest rows to stay under the limit.
 * The check interval is configurable (default: every 15 minutes).
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    public const string MODE_DEFERRED  = 'deferred';
    public const string MODE_IMMEDIATE = 'immediate';

    private static bool $flushing = false;
    private static float $lastTruncateCheck = 0.0;

    private bool $enabled = false;
    private string $writeMode = self::MODE_DEFERRED;

    /** @var string[] channels to log; empty = all */
    private array $channels = [];

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    private int $maxSizeMb = 500;
    private int $truncateIntervalMin = 15;
    private bool $groupingEnabled = true;
    private int $groupingKeyLength = 30;

    public function __construct(
        private readonly Connection $connection,
        int|string $level = Logger::DEBUG,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setWriteMode(string $mode): void
    {
        $this->writeMode = in_array($mode, [self::MODE_DEFERRED, self::MODE_IMMEDIATE], true)
            ? $mode
            : self::MODE_DEFERRED;
    }

    public function getWriteMode(): string
    {
        return $this->writeMode;
    }

    /** @param string[] $channels empty = all channels */
    public function setChannels(array $channels): void
    {
        $this->channels = array_filter(array_map('trim', $channels));
    }

    /** @return string[] */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function getBufferCount(): int
    {
        return count($this->buffer);
    }

    public function setMaxSizeMb(int $maxSizeMb): void
    {
        $this->maxSizeMb = max(1, $maxSizeMb);
    }

    public function getMaxSizeMb(): int
    {
        return $this->maxSizeMb;
    }

    public function setTruncateIntervalMin(int $minutes): void
    {
        $this->truncateIntervalMin = max(1, $minutes);
    }

    public function getTruncateIntervalMin(): int
    {
        return $this->truncateIntervalMin;
    }

    public function setGroupingEnabled(bool $enabled): void
    {
        $this->groupingEnabled = $enabled;
    }

    public function isGroupingEnabled(): bool
    {
        return $this->groupingEnabled;
    }

    public function setGroupingKeyLength(int $length): void
    {
        $this->groupingKeyLength = max(10, min(255, $length));
    }

    public function getGroupingKeyLength(): int
    {
        return $this->groupingKeyLength;
    }

    protected function write(array $record): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->channels !== [] && !in_array($record['channel'] ?? '', $this->channels, true)) {
            return;
        }

        $row = $this->prepareRow($record);

        if ($this->writeMode === self::MODE_IMMEDIATE) {
            $this->insertRow($row);
            $this->autoTruncateIfDue();
        } else {
            $this->buffer[] = $row;
        }
    }

    public function flush(): void
    {
        if ($this->buffer === [] || self::$flushing) {
            return;
        }

        self::$flushing = true;

        try {
            foreach ($this->buffer as $row) {
                $this->insertRow($row);
            }
        } finally {
            $this->buffer = [];
            self::$flushing = false;
        }

        $this->autoTruncateIfDue();
    }

    public function reset(): void
    {
        $this->buffer = [];
    }

    /**
     * Check if enough time has passed since last truncation check,
     * and if so, truncate old rows if the table exceeds max size.
     */
    private function autoTruncateIfDue(): void
    {
        $now = microtime(true);
        $intervalSeconds = $this->truncateIntervalMin * 60;

        if (($now - self::$lastTruncateCheck) < $intervalSeconds) {
            return;
        }

        self::$lastTruncateCheck = $now;

        try {
            $this->truncateIfOversized();
        } catch (\Throwable) {
            // Never let cleanup break logging
        }
    }

    private function truncateIfOversized(): void
    {
        $sizeBytes = $this->getTableSizeBytes();
        if ($sizeBytes === null) {
            return;
        }

        $limitBytes = $this->maxSizeMb * 1024 * 1024;
        if ($sizeBytes <= $limitBytes) {
            return;
        }

        $totalRows = $this->getRowCount();
        if ($totalRows <= 0) {
            return;
        }

        $avgRowBytes = (int) ceil($sizeBytes / $totalRows);
        if ($avgRowBytes <= 0) {
            return;
        }

        $excessBytes = $sizeBytes - $limitBytes;
        // Delete 20% extra to avoid re-triggering on the next check
        $rowsToDelete = (int) ceil(($excessBytes * 1.2) / $avgRowBytes);
        $rowsToDelete = min($rowsToDelete, (int) ($totalRows * 0.9));

        if ($rowsToDelete <= 0) {
            return;
        }

        $this->deleteOldestRows($rowsToDelete);
    }

    private function getTableSizeBytes(): ?int
    {
        try {
            $result = $this->connection->executeQuery(
                "SELECT pg_total_relation_size('genaker_log_entry')"
            )->fetchOne();

            return $result !== false ? (int) $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getRowCount(): int
    {
        try {
            $result = $this->connection->executeQuery(
                "SELECT reltuples::bigint FROM pg_class WHERE relname = 'genaker_log_entry'"
            )->fetchOne();

            if ($result !== false && (int) $result > 0) {
                return (int) $result;
            }

            return (int) $this->connection->executeQuery(
                'SELECT COUNT(*) FROM genaker_log_entry'
            )->fetchOne();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function deleteOldestRows(int $count): void
    {
        $this->connection->executeStatement(
            'DELETE FROM genaker_log_entry WHERE id IN (
                SELECT id FROM genaker_log_entry ORDER BY id ASC LIMIT :lim
            )',
            ['lim' => $count],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
    }

    private function insertRow(array $row): void
    {
        if (self::$flushing && $this->writeMode === self::MODE_IMMEDIATE) {
            return;
        }

        $inFlush = self::$flushing;
        if (!$inFlush) {
            self::$flushing = true;
        }

        try {
            if ($this->groupingEnabled && $row['message_key'] !== null) {
                $this->upsertRow($row);
            } else {
                $this->connection->insert('genaker_log_entry', $row);
            }
        } catch (\Throwable) {
            // Silently drop — never let a log-write failure cascade
        } finally {
            if (!$inFlush) {
                self::$flushing = false;
            }
        }
    }

    private function upsertRow(array $row): void
    {
        $now = $row['created_at'];

        $this->connection->executeStatement(
            'INSERT INTO genaker_log_entry
                (channel, level, level_name, message, context, extra, created_at, url, ip, message_key, occurrence_count, first_seen_at)
             VALUES
                (:channel, :level, :level_name, :message, :context, :extra, :created_at, :url, :ip, :message_key, 1, :first_seen_at)
             ON CONFLICT (message_key) DO UPDATE SET
                occurrence_count = genaker_log_entry.occurrence_count + 1,
                created_at       = :created_at,
                context          = :context,
                extra            = :extra,
                url              = :url,
                ip               = :ip',
            [
                'channel'       => $row['channel'],
                'level'         => $row['level'],
                'level_name'    => $row['level_name'],
                'message'       => $row['message'],
                'context'       => $row['context'],
                'extra'         => $row['extra'],
                'created_at'    => $now,
                'url'           => $row['url'],
                'ip'            => $row['ip'],
                'message_key'   => $row['message_key'],
                'first_seen_at' => $now,
            ]
        );
    }

    private function prepareRow(array $record): array
    {
        $context = $record['context'] ?? [];
        $extra   = $record['extra'] ?? [];

        $url = $context['url']
            ?? $extra['url']
            ?? ($_SERVER['REQUEST_URI'] ?? null);
        $ip = $context['ip']
            ?? $extra['ip']
            ?? ($_SERVER['REMOTE_ADDR'] ?? null);

        $channel   = mb_substr((string) ($record['channel'] ?? 'app'), 0, 64);
        $level     = (int) ($record['level'] ?? Logger::DEBUG);
        $levelName = mb_substr((string) ($record['level_name'] ?? 'DEBUG'), 0, 20);
        $message   = mb_substr((string) ($record['message'] ?? ''), 0, 65535);
        $now       = (new \DateTime())->format('Y-m-d H:i:s');

        $messageKey = null;
        if ($this->groupingEnabled) {
            $keySource = $channel . '|' . $level . '|' . mb_substr($message, 0, $this->groupingKeyLength);
            $messageKey = md5($keySource);
        }

        return [
            'channel'          => $channel,
            'level'            => $level,
            'level_name'       => $levelName,
            'message'          => $message,
            'context'          => $this->safeJsonEncode($context),
            'extra'            => $this->safeJsonEncode($extra),
            'created_at'       => $now,
            'url'              => $url !== null ? mb_substr((string) $url, 0, 2000) : null,
            'ip'               => $ip !== null ? mb_substr((string) $ip, 0, 45) : null,
            'message_key'      => $messageKey,
            'occurrence_count' => 1,
            'first_seen_at'    => $now,
        ];
    }

    private function safeJsonEncode(mixed $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (\JsonException) {
            return json_encode(['_serialization_error' => 'Failed to encode context/extra']);
        }
    }
}
