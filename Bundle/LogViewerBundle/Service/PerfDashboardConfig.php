<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Provides access to server monitoring configuration settings.
 */
class PerfDashboardConfig
{
    private const string KEY_ENABLED        = 'genaker_log_viewer.perf_dashboard_enabled';
    private const string KEY_INTERVAL       = 'genaker_log_viewer.perf_report_interval';
    private const string KEY_HTTP_REPORTING = 'genaker_log_viewer.perf_http_reporting';
    private const string KEY_MQ_REPORTING   = 'genaker_log_viewer.perf_mq_reporting';
    private const string KEY_MQ_TRIGGER     = 'genaker_log_viewer.perf_mq_trigger';

    private const string KEY_HTTP_PERF_ENABLED  = 'genaker_log_viewer.http_perf_enabled';
    private const string KEY_HTTP_PERF_STATUSES = 'genaker_log_viewer.http_perf_tracked_statuses';
    private const string KEY_HTTP_PERF_CLI      = 'genaker_log_viewer.http_perf_track_cli';
    private const string KEY_HTTP_PERF_MQ       = 'genaker_log_viewer.http_perf_track_mq';
    private const string KEY_HTTP_SLOW_MS        = 'genaker_log_viewer.http_perf_slow_threshold_ms';

    private const string KEY_SQL_ENABLED      = 'genaker_log_viewer.sql_tracking_enabled';
    private const string KEY_SQL_N1_THRESHOLD = 'genaker_log_viewer.sql_n1_threshold';
    private const string KEY_SQL_SLOW_MS      = 'genaker_log_viewer.sql_slow_threshold_ms';
    private const string KEY_SQL_AI_API_KEY   = 'genaker_log_viewer.sql_ai_api_key';
    private const string KEY_SQL_AI_API_URL   = 'genaker_log_viewer.sql_ai_api_url';
    private const string KEY_SQL_AI_MODEL     = 'genaker_log_viewer.sql_ai_model';

    private const int DEFAULT_INTERVAL = 60;

    public function __construct(
        private readonly ConfigManager $configManager
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->configManager->get(self::KEY_ENABLED, true);
    }

    public function getReportInterval(): int
    {
        $interval = $this->configManager->get(self::KEY_INTERVAL, self::DEFAULT_INTERVAL);

        return max(5, min(300, (int) $interval));
    }

    public function isHttpReportingEnabled(): bool
    {
        return (bool) $this->configManager->get(self::KEY_HTTP_REPORTING, true);
    }

    public function isMqReportingEnabled(): bool
    {
        return (bool) $this->configManager->get(self::KEY_MQ_REPORTING, true);
    }

    public function getMqTriggerMode(): string
    {
        $mode = $this->configManager->get(self::KEY_MQ_TRIGGER, 'after');

        return (string) $mode;
    }

    public function shouldTriggerOnMqBefore(): bool
    {
        $mode = $this->getMqTriggerMode();

        return in_array($mode, ['before', 'both'], true);
    }

    public function shouldTriggerOnMqAfter(): bool
    {
        $mode = $this->getMqTriggerMode();

        return in_array($mode, ['after', 'both'], true);
    }

    public function isHttpPerfEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_HTTP_PERF_ENABLED);

        return $value === null ? true : (bool) $value;
    }

    /** @return int[] */
    public function getTrackedStatusCodes(): array
    {
        $value = $this->configManager->get(self::KEY_HTTP_PERF_STATUSES);
        $raw   = (string) ($value ?? '200');

        return array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', $raw)))
        ));
    }

    public function isStatusTracked(int $statusCode): bool
    {
        $tracked = $this->getTrackedStatusCodes();

        return empty($tracked) || in_array($statusCode, $tracked, true);
    }

    public function isCliPerfEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_HTTP_PERF_CLI);

        return $value === null ? true : (bool) $value;
    }

    public function isMqPerfEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_HTTP_PERF_MQ);

        return $value === null ? true : (bool) $value;
    }

    public function getHttpSlowThresholdMs(): float
    {
        $value = $this->configManager->get(self::KEY_HTTP_SLOW_MS);

        return max(0.0, (float) ($value ?? 0.0));
    }

    public function isSqlTrackingEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_SQL_ENABLED);

        return $value === null ? true : (bool) $value;
    }

    public function getSqlN1Threshold(): int
    {
        $value = $this->configManager->get(self::KEY_SQL_N1_THRESHOLD);

        return max(2, (int) ($value ?? 5));
    }

    public function getSqlSlowThresholdMs(): float
    {
        $value = $this->configManager->get(self::KEY_SQL_SLOW_MS);

        return max(1.0, (float) ($value ?? 10.0));
    }

    public function getSqlAiApiKey(): string
    {
        return (string) ($this->configManager->get(self::KEY_SQL_AI_API_KEY) ?? '');
    }

    public function getSqlAiApiUrl(): string
    {
        return (string) ($this->configManager->get(self::KEY_SQL_AI_API_URL) ?? '');
    }

    public function getSqlAiModel(): string
    {
        return (string) ($this->configManager->get(self::KEY_SQL_AI_MODEL) ?? '');
    }
}
