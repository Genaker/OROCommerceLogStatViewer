<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;

class PerfDashboardConfigTest extends TestCase
{
    private StubConfigManager $configManager;
    private PerfDashboardConfig $config;

    protected function setUp(): void
    {
        $this->configManager = new StubConfigManager();
        $this->config = new PerfDashboardConfig($this->configManager);
    }

    /** @test */
    public function testIsEnabledDefaultsToTrue(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_dashboard_enabled', true);

        $this->assertTrue($this->config->isEnabled());
    }

    /** @test */
    public function testGetReportIntervalDefaultsToSixtySeconds(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_report_interval', 60);

        $this->assertSame(60, $this->config->getReportInterval());
    }

    /** @test */
    public function testGetReportIntervalClampsBelowMinimum(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_report_interval', 3);

        $this->assertSame(5, $this->config->getReportInterval());
    }

    /** @test */
    public function testGetReportIntervalClampsAboveMaximum(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_report_interval', 500);

        $this->assertSame(300, $this->config->getReportInterval());
    }

    /** @test */
    public function testIsHttpReportingEnabledDefaultsToTrue(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_http_reporting', true);

        $this->assertTrue($this->config->isHttpReportingEnabled());
    }

    /** @test */
    public function testIsMqReportingEnabledDefaultsToTrue(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_mq_reporting', true);

        $this->assertTrue($this->config->isMqReportingEnabled());
    }

    /** @test */
    public function testGetMqTriggerModeDefaultsToAfter(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_mq_trigger', 'after');

        $this->assertSame('after', $this->config->getMqTriggerMode());
    }

    /** @test */
    public function testShouldTriggerOnMqBeforeReturnsTrueForBeforeMode(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_mq_trigger', 'before');

        $this->assertTrue($this->config->shouldTriggerOnMqBefore());
        $this->assertFalse($this->config->shouldTriggerOnMqAfter());
    }

    /** @test */
    public function testShouldTriggerOnMqAfterReturnsTrueForAfterMode(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_mq_trigger', 'after');

        $this->assertFalse($this->config->shouldTriggerOnMqBefore());
        $this->assertTrue($this->config->shouldTriggerOnMqAfter());
    }

    /** @test */
    public function testShouldTriggerOnBothForBothMode(): void
    {
        $this->configManager->set('genaker_log_viewer.perf_mq_trigger', 'both');

        $this->assertTrue($this->config->shouldTriggerOnMqBefore());
        $this->assertTrue($this->config->shouldTriggerOnMqAfter());
    }

    // ── HTTP performance tracking ─────────────────────────────────────────────

    /** @test */
    public function testIsHttpPerfEnabledDefaultsToTrueWhenNotConfigured(): void
    {
        // Key not set → null → defaults to true
        $this->assertTrue($this->config->isHttpPerfEnabled());
    }

    /** @test */
    public function testIsHttpPerfEnabledReturnsTrueWhenExplicitlyEnabled(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_enabled', true);

        $this->assertTrue($this->config->isHttpPerfEnabled());
    }

    /** @test */
    public function testIsHttpPerfEnabledReturnsFalseWhenDisabled(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_enabled', false);

        $this->assertFalse($this->config->isHttpPerfEnabled());
    }

    /** @test */
    public function testGetTrackedStatusCodesParsesCommaSeparatedString(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_tracked_statuses', '200,201,302');

        $this->assertSame([200, 201, 302], $this->config->getTrackedStatusCodes());
    }

    /** @test */
    public function testGetTrackedStatusCodesDefaultsToTwoHundred(): void
    {
        // Key not set — falls back to default '200'
        $this->assertSame([200], $this->config->getTrackedStatusCodes());
    }

    /** @test */
    public function testGetTrackedStatusCodesHandlesWhitespace(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_tracked_statuses', ' 200 , 201 ');

        $this->assertSame([200, 201], $this->config->getTrackedStatusCodes());
    }

    /** @test */
    public function testIsStatusTrackedReturnsTrueForTrackedCode(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_tracked_statuses', '200');

        $this->assertTrue($this->config->isStatusTracked(200));
    }

    /** @test */
    public function testIsStatusTrackedReturnsFalseForUntrackedCode(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_tracked_statuses', '200');

        $this->assertFalse($this->config->isStatusTracked(404));
    }

    /** @test */
    public function testIsStatusTrackedReturnsTrueForEmptyList(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_tracked_statuses', '');

        // Empty list means track everything
        $this->assertTrue($this->config->isStatusTracked(404));
        $this->assertTrue($this->config->isStatusTracked(500));
    }

    /** @test */
    public function testIsCliPerfEnabledDefaultsToTrueWhenNotConfigured(): void
    {
        $this->assertTrue($this->config->isCliPerfEnabled());
    }

    /** @test */
    public function testIsCliPerfEnabledReturnsTrueWhenExplicitlyEnabled(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_track_cli', true);

        $this->assertTrue($this->config->isCliPerfEnabled());
    }

    /** @test */
    public function testIsCliPerfEnabledReturnsFalseWhenDisabled(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_track_cli', false);

        $this->assertFalse($this->config->isCliPerfEnabled());
    }

    /** @test */
    public function testIsMqPerfEnabledDefaultsToTrueWhenNotConfigured(): void
    {
        $this->assertTrue($this->config->isMqPerfEnabled());
    }

    /** @test */
    public function testIsMqPerfEnabledReturnsTrueWhenExplicitlyEnabled(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_track_mq', true);

        $this->assertTrue($this->config->isMqPerfEnabled());
    }

    /** @test */
    public function testIsMqPerfEnabledReturnsFalseWhenDisabled(): void
    {
        $this->configManager->set('genaker_log_viewer.http_perf_track_mq', false);

        $this->assertFalse($this->config->isMqPerfEnabled());
    }

    // ── AI Analysis configuration ────────────────────────────────────────────

    /** @test */
    public function testGetSqlAiApiKeyReturnsEmptyStringWhenNotConfigured(): void
    {
        $this->assertSame('', $this->config->getSqlAiApiKey());
    }

    /** @test */
    public function testGetSqlAiApiKeyReturnsConfiguredValue(): void
    {
        $this->configManager->set('genaker_log_viewer.sql_ai_api_key', 'sk-test-key');
        $this->assertSame('sk-test-key', $this->config->getSqlAiApiKey());
    }

    /** @test */
    public function testGetSqlAiApiKeyHandlesNullFromConfig(): void
    {
        $this->configManager->set('genaker_log_viewer.sql_ai_api_key', null);
        $this->assertSame('', $this->config->getSqlAiApiKey());
    }

    /** @test */
    public function testGetSqlAiApiUrlReturnsEmptyStringWhenNotConfigured(): void
    {
        $this->assertSame('', $this->config->getSqlAiApiUrl());
    }

    /** @test */
    public function testGetSqlAiApiUrlReturnsConfiguredValue(): void
    {
        $this->configManager->set('genaker_log_viewer.sql_ai_api_url', 'https://custom.ai/v1/chat');
        $this->assertSame('https://custom.ai/v1/chat', $this->config->getSqlAiApiUrl());
    }

    /** @test */
    public function testGetSqlAiModelReturnsEmptyStringWhenNotConfigured(): void
    {
        $this->assertSame('', $this->config->getSqlAiModel());
    }

    /** @test */
    public function testGetSqlAiModelReturnsConfiguredValue(): void
    {
        $this->configManager->set('genaker_log_viewer.sql_ai_model', 'gpt-4o');
        $this->assertSame('gpt-4o', $this->config->getSqlAiModel());
    }

    /** @test */
    public function testGetSqlAiModelHandlesNullFromConfig(): void
    {
        $this->configManager->set('genaker_log_viewer.sql_ai_model', null);
        $this->assertSame('', $this->config->getSqlAiModel());
    }
}

class StubConfigManager extends ConfigManager
{
    private array $config = [];

    public function __construct()
    {
    }

    public function set(string $name, mixed $value, object|int|null $scopeIdentifier = null): void
    {
        $this->config[$name] = $value;
    }

    public function get(string $name, mixed $default = false, bool $full = false, object|int|null $scopeIdentifier = null): mixed
    {
        return array_key_exists($name, $this->config) ? $this->config[$name] : null;
    }
}
