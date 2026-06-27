<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Fixtures;

use Genaker\Bundle\LogViewerBundle\Tests\Fixtures\LogFileProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LogFileProvider fixture.
 */
class LogFileProviderTest extends TestCase
{
    private string $testDir;
    private LogFileProvider $provider;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/log_provider_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        $this->provider = new LogFileProvider($this->testDir);
    }

    protected function tearDown(): void
    {
        $this->provider->cleanupTestLogFiles();
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    }

    public function testCreateTestLogFilesCreatesMultipleFiles(): void
    {
        $this->provider->createTestLogFiles();

        $logFiles = glob($this->testDir . '/*.log');
        $this->assertNotEmpty($logFiles);
        $this->assertGreaterThanOrEqual(5, count($logFiles));

        $fileNames = array_map('basename', $logFiles);
        $this->assertContains('app.log', $fileNames);
        $this->assertContains('security.log', $fileNames);
        $this->assertContains('error.log', $fileNames);
        $this->assertContains('database.log', $fileNames);
        $this->assertContains('cache.log', $fileNames);
    }

    public function testCreateTestLogFilesCreatesNonEmptyFiles(): void
    {
        $this->provider->createTestLogFiles();

        $logFiles = glob($this->testDir . '/*.log');
        foreach ($logFiles as $file) {
            $size = filesize($file);
            $this->assertGreaterThan(0, $size, "File $file is empty");
        }
    }

    public function testCreateTestLogFilesGeneratesRealisticContent(): void
    {
        $this->provider->createTestLogFiles();

        $appLogPath = $this->testDir . '/app.log';
        $this->assertFileExists($appLogPath);

        $content = file_get_contents($appLogPath);
        $this->assertStringContainsString('Application', $content);
        $this->assertStringContainsString('[', $content);
        $this->assertStringContainsString(']', $content);
    }

    public function testCreateLogFileWithSpecificLineCount(): void
    {
        $this->provider->createLogFile('custom.log', 100);

        $logPath = $this->testDir . '/custom.log';
        $this->assertFileExists($logPath);

        $content = file_get_contents($logPath);
        $lines = count(array_filter(explode("\n", $content), 'strlen'));
        $this->assertEquals(100, $lines);
    }

    public function testCreateLogFileWithDefaultLineCount(): void
    {
        $this->provider->createLogFile('default.log');

        $logPath = $this->testDir . '/default.log';
        $this->assertFileExists($logPath);

        $content = file_get_contents($logPath);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString("\n", $content);
    }

    public function testCreateLogFileAddsExtension(): void
    {
        $this->provider->createLogFile('test.log', 5);

        $logPath = $this->testDir . '/test.log';
        $this->assertFileExists($logPath);
        $this->assertTrue(is_file($logPath));
        $this->assertStringEndsWith('.log', $logPath);
    }

    public function testCleanupTestLogFilesRemovesAllLogFiles(): void
    {
        $this->provider->createTestLogFiles();
        $this->assertGreaterThan(0, count(glob($this->testDir . '/*.log')));

        $this->provider->cleanupTestLogFiles();
        $this->assertEmpty(glob($this->testDir . '/*.log'));
    }

    public function testCleanupTestLogFilesHandlesEmptyDirectory(): void
    {
        // Directory is empty, cleanup should not throw exception
        $this->provider->cleanupTestLogFiles();
        $this->assertTrue(is_dir($this->testDir));
    }

    public function testCreateLogFileWithLargeLineCount(): void
    {
        $this->provider->createLogFile('large.log', 1000);

        $logPath = $this->testDir . '/large.log';
        $this->assertFileExists($logPath);

        $content = file_get_contents($logPath);
        $lines = count(array_filter(explode("\n", $content), 'strlen'));
        $this->assertEquals(1000, $lines);
    }

    public function testCreateMultipleLogFilesIndependently(): void
    {
        $this->provider->createLogFile('log1.log', 10);
        $this->provider->createLogFile('log2.log', 20);
        $this->provider->createLogFile('log3.log', 30);

        $logFiles = glob($this->testDir . '/*.log');
        $this->assertCount(3, $logFiles);

        $sizes = [];
        foreach ($logFiles as $file) {
            $sizes[] = filesize($file);
        }
        $this->assertCount(3, array_unique($sizes));
    }

    public function testLogContentContainsTimestamps(): void
    {
        $this->provider->createLogFile('timestamp.log', 5);

        $content = file_get_contents($this->testDir . '/timestamp.log');
        // Should contain date format YYYY-MM-DD
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $content);
        // Should contain time format HH:MM:SS
        $this->assertMatchesRegularExpression('/\d{2}:\d{2}:\d{2}/', $content);
    }

    public function testLogContentContainsLogLevels(): void
    {
        $this->provider->createLogFile('levels.log', 50);

        $content = file_get_contents($this->testDir . '/levels.log');
        $logLevels = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'];

        // At least some log levels should be present
        $foundLevels = 0;
        foreach ($logLevels as $level) {
            if (strpos($content, $level) !== false) {
                $foundLevels++;
            }
        }
        $this->assertGreaterThan(0, $foundLevels);
    }

    public function testCreateTestLogFilesWithStaggeredTimestamps(): void
    {
        $this->provider->createTestLogFiles();

        $logFiles = glob($this->testDir . '/*.log');
        $timestamps = [];

        foreach ($logFiles as $file) {
            $timestamps[] = filemtime($file);
        }

        // Files should have different timestamps (not all the same)
        $uniqueTimestamps = count(array_unique($timestamps));
        $this->assertGreaterThan(1, $uniqueTimestamps);
    }

    public function testCreateLogFilePermissions(): void
    {
        $this->provider->createLogFile('permissions.log', 5);

        $logPath = $this->testDir . '/permissions.log';
        $this->assertTrue(is_readable($logPath));
        $this->assertTrue(is_writable($logPath));
    }
}
