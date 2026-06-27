<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive unit tests for LogFileReader service.
 */
class LogFileReaderTest extends TestCase
{
    private string $testDir;
    private LogFileReader $reader;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/log_reader_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        $this->reader = new LogFileReader($this->testDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            foreach (glob($this->testDir . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testDir);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests for getFullPath()
    // ─────────────────────────────────────────────────────────────────────

    public function testGetFullPathReturnsCorrectPath(): void
    {
        $result = $this->reader->getFullPath('test.log');
        $this->assertEquals($this->testDir . '/test.log', $result);
    }

    public function testGetFullPathWithSpecialCharacters(): void
    {
        $fileName = 'test-2024_01_15.log';
        $result = $this->reader->getFullPath($fileName);
        $this->assertEquals($this->testDir . '/' . $fileName, $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests for readTail()
    // ─────────────────────────────────────────────────────────────────────

    public function testReadTailReturnsContentAndOffset(): void
    {
        $logFile = $this->testDir . '/test.log';
        $content = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        file_put_contents($logFile, $content);

        $result = $this->reader->readTail('test.log', 2);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('offset', $result);
        $this->assertStringContainsString('Line 4', $result['content']);
        $this->assertStringContainsString('Line 5', $result['content']);
        $this->assertEquals(filesize($logFile), $result['offset']);
    }

    public function testReadTailWithExactLineCount(): void
    {
        $logFile = $this->testDir . '/test.log';
        $lines = array_fill(0, 50, 'Line');
        file_put_contents($logFile, implode("\n", $lines));

        $result = $this->reader->readTail('test.log', 10);

        $returnedLines = count(array_filter(explode("\n", $result['content']), 'strlen'));
        $this->assertLessThanOrEqual(10, $returnedLines);
    }

    public function testReadTailWithMoreLinesThanFile(): void
    {
        $logFile = $this->testDir . '/test.log';
        file_put_contents($logFile, "Line 1\nLine 2\nLine 3");

        $result = $this->reader->readTail('test.log', 100);

        $this->assertStringContainsString('Line 1', $result['content']);
        $this->assertStringContainsString('Line 3', $result['content']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests for readFromOffset()
    // ─────────────────────────────────────────────────────────────────────

    public function testReadFromOffsetReturnsNewContent(): void
    {
        $logFile = $this->testDir . '/test.log';
        $content = 'Initial content here';
        file_put_contents($logFile, $content);

        $result = $this->reader->readFromOffset('test.log', 8);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('newContent', $result);
        $this->assertArrayHasKey('newOffset', $result);
        $this->assertStringContainsString('content', $result['newContent']);
        $this->assertGreaterThan(8, $result['newOffset']);
    }

    public function testReadFromOffsetWithZeroOffset(): void
    {
        $content = 'Complete file content';
        file_put_contents($this->testDir . '/test.log', $content);

        $result = $this->reader->readFromOffset('test.log', 0);

        $this->assertEquals($content, $result['newContent']);
        $this->assertEquals(strlen($content), $result['newOffset']);
    }

    public function testReadFromOffsetWithOffsetEqualToSize(): void
    {
        $logFile = $this->testDir . '/test.log';
        $content = 'Test content';
        file_put_contents($logFile, $content);
        $size = strlen($content);

        $result = $this->reader->readFromOffset('test.log', $size);

        $this->assertEquals('', $result['newContent']);
        $this->assertEquals($size, $result['newOffset']);
    }

    public function testReadFromOffsetPartialContent(): void
    {
        $content = 'ABCDEFGHIJ';
        file_put_contents($this->testDir . '/test.log', $content);

        $result = $this->reader->readFromOffset('test.log', 5);

        $this->assertEquals('FGHIJ', $result['newContent']);
        $this->assertEquals(10, $result['newOffset']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests for getLogFiles()
    // ─────────────────────────────────────────────────────────────────────

    public function testGetLogFilesReturnsEmptyForNonExistentDir(): void
    {
        $reader = new LogFileReader('/nonexistent/directory/path');
        $files = $reader->getLogFiles();

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testGetLogFilesDetectsLogFiles(): void
    {
        file_put_contents($this->testDir . '/app.log', 'App logs');
        file_put_contents($this->testDir . '/security.log', 'Security logs');
        file_put_contents($this->testDir . '/readme.txt', 'Not a log');

        $files = $this->reader->getLogFiles();

        $this->assertCount(2, $files);
        $fileNames = array_column($files, 'file_name');
        $this->assertContains('app.log', $fileNames);
        $this->assertContains('security.log', $fileNames);
        $this->assertNotContains('readme.txt', $fileNames);
    }

    public function testGetLogFilesIncludesFileMetadata(): void
    {
        $content = 'Test log content';
        file_put_contents($this->testDir . '/test.log', $content);

        $files = $this->reader->getLogFiles();

        $this->assertCount(1, $files);
        $file = $files[0];
        $this->assertArrayHasKey('file_name', $file);
        $this->assertArrayHasKey('size', $file);
        $this->assertArrayHasKey('modified', $file);
        $this->assertEquals('test.log', $file['file_name']);
        $this->assertIsString($file['size']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?\s+[KMGTP]?B$/', $file['size']);
        $this->assertIsString($file['modified']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $file['modified']);
    }

    public function testGetLogFilesReturnsCorrectFileSize(): void
    {
        $content = 'Exactly 20 bytes!!!';
        file_put_contents($this->testDir . '/test.log', $content);

        $files = $this->reader->getLogFiles();

        $this->assertIsString($files[0]['size']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?\s+[KMGTP]?B$/', $files[0]['size']);
    }

    public function testGetLogFilesOrderedByModificationTimeNewestFirst(): void
    {
        file_put_contents($this->testDir . '/oldest.log', 'Old');
        sleep(1);
        file_put_contents($this->testDir . '/middle.log', 'Middle');
        sleep(1);
        file_put_contents($this->testDir . '/newest.log', 'Newest');

        $files = $this->reader->getLogFiles();

        $this->assertCount(3, $files);
        $this->assertEquals('newest.log', $files[0]['file_name']);
        $this->assertEquals('middle.log', $files[1]['file_name']);
        $this->assertEquals('oldest.log', $files[2]['file_name']);
    }

    public function testGetLogFilesIgnoresNonLogExtensions(): void
    {
        file_put_contents($this->testDir . '/app.log', 'App');
        file_put_contents($this->testDir . '/app.txt', 'Text');
        file_put_contents($this->testDir . '/app.json', 'JSON');
        file_put_contents($this->testDir . '/app.log.bak', 'Backup');

        $files = $this->reader->getLogFiles();

        $this->assertCount(1, $files);
        $this->assertEquals('app.log', $files[0]['file_name']);
    }

    public function testGetLogFilesWithMultipleFiles(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->testDir . "/log$i.log", "Log content $i");
        }

        $files = $this->reader->getLogFiles();

        $this->assertCount(5, $files);
        $this->assertIsArray($files);
    }

    public function testGetLogFilesReturnsListType(): void
    {
        file_put_contents($this->testDir . '/test.log', 'Content');

        $files = $this->reader->getLogFiles();

        $this->assertIsArray($files);
        if (!empty($files)) {
            foreach ($files as $key => $file) {
                $this->assertIsInt($key);
                $this->assertIsArray($file);
            }
        }
    }

    public function testGetLogFilesEmptyDirectory(): void
    {
        $files = $this->reader->getLogFiles();

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testGetLogFilesWithLargeNumberOfFiles(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            file_put_contents($this->testDir . "/log_$i.log", "Log $i");
        }

        $files = $this->reader->getLogFiles();

        $this->assertCount(20, $files);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests for aggregateUniqueEntries()
    // ─────────────────────────────────────────────────────────────────────

    public function testAggregateUniqueEntriesReturnsEmptyForEmptyFile(): void
    {
        file_put_contents($this->testDir . '/app.log', '');

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertSame([], $result);
    }

    public function testAggregateUniqueEntriesExcludesSingleOccurrenceLines(): void
    {
        $lines = [
            '[2026-05-22T15:31:01+00:00] app.WARNING: First unique message [] []',
            '[2026-05-22T15:31:02+00:00] app.WARNING: Second unique message [] []',
            '[2026-05-22T15:31:03+00:00] app.WARNING: Third unique message [] []',
        ];
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertSame([], $result);
    }

    public function testAggregateUniqueEntriesGroupsDuplicateLines(): void
    {
        $repeatedMsg = 'app.WARNING: Extended field "egQuoteId" skipped [] []';
        $lines = [
            '[2026-05-22T15:31:01+00:00] ' . $repeatedMsg,
            '[2026-05-22T15:31:02+00:00] ' . $repeatedMsg,
            '[2026-05-22T15:31:03+00:00] ' . $repeatedMsg,
            '[2026-05-22T15:31:04+00:00] app.INFO: Some other line [] []',
        ];
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertCount(1, $result);
        $this->assertSame(3, $result[0]['count']);
        $this->assertStringContainsString('egQuoteId', $result[0]['message']);
    }

    public function testAggregateUniqueEntriesStripsTimestampForGrouping(): void
    {
        $msg = 'order_import.WARNING: Setter unavailable for field "egSapTotalPrice" [] []';
        $lines = [
            '[2026-05-22T10:00:00+00:00] ' . $msg,
            '[2026-05-22T11:00:00+00:00] ' . $msg,
            '[2026-05-22T12:00:00+00:00] ' . $msg,
            '[2026-05-22T13:00:00+00:00] ' . $msg,
        ];
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertCount(1, $result);
        $this->assertSame(4, $result[0]['count']);
    }

    public function testAggregateUniqueEntriesExtractsChannelAndLevel(): void
    {
        $msg = 'order_import.WARNING: Some repeated warning [] []';
        $lines = [
            '[2026-05-22T10:00:00+00:00] ' . $msg,
            '[2026-05-22T10:00:01+00:00] ' . $msg,
        ];
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertCount(1, $result);
        $this->assertSame('order_import', $result[0]['channel']);
        $this->assertSame('WARNING', $result[0]['level']);
    }

    public function testAggregateUniqueEntriesRecordsFirstAndLastSeen(): void
    {
        $msg = 'app.ERROR: Database connection failed [] []';
        $lines = [
            '[2026-05-22T08:00:00+00:00] ' . $msg,
            '[2026-05-22T09:00:00+00:00] ' . $msg,
            '[2026-05-22T10:30:00+00:00] ' . $msg,
        ];
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('2026-05-22T08:00:00', $result[0]['firstSeen']);
        $this->assertStringContainsString('2026-05-22T10:30:00', $result[0]['lastSeen']);
    }

    public function testAggregateUniqueEntriesSortsByCountDescending(): void
    {
        $msgA = 'app.WARNING: Message A repeated [] []';
        $msgB = 'app.WARNING: Message B repeated more [] []';
        $msgC = 'app.WARNING: Message C repeated most [] []';
        $lines = array_merge(
            array_fill(0, 2, '[2026-05-22T10:00:00+00:00] ' . $msgA),
            array_fill(0, 7, '[2026-05-22T10:00:00+00:00] ' . $msgB),
            array_fill(0, 4, '[2026-05-22T10:00:00+00:00] ' . $msgC),
        );
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertCount(3, $result);
        $this->assertGreaterThanOrEqual($result[0]['count'], $result[0]['count']);
        $this->assertSame(7, $result[0]['count']);
        $this->assertSame(4, $result[1]['count']);
        $this->assertSame(2, $result[2]['count']);
    }

    public function testAggregateUniqueEntriesReturnsStructuredArray(): void
    {
        $msg = 'app.CRITICAL: Out of memory [] []';
        $lines = [
            '[2026-05-22T10:00:00+00:00] ' . $msg,
            '[2026-05-22T10:00:01+00:00] ' . $msg,
        ];
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertNotEmpty($result);
        $entry = $result[0];
        $this->assertArrayHasKey('message', $entry);
        $this->assertArrayHasKey('level', $entry);
        $this->assertArrayHasKey('channel', $entry);
        $this->assertArrayHasKey('count', $entry);
        $this->assertArrayHasKey('firstSeen', $entry);
        $this->assertArrayHasKey('lastSeen', $entry);
        $this->assertIsString($entry['message']);
        $this->assertIsString($entry['level']);
        $this->assertIsString($entry['channel']);
        $this->assertIsInt($entry['count']);
    }

    public function testAggregateUniqueEntriesHandlesMultipleChannelsAndLevels(): void
    {
        $warnMsg = 'order_import.WARNING: Skipped field [] []';
        $errMsg  = 'payment.ERROR: Gateway timeout [] []';
        $lines = array_merge(
            array_fill(0, 3, '[2026-05-22T10:00:00+00:00] ' . $warnMsg),
            array_fill(0, 5, '[2026-05-22T10:00:00+00:00] ' . $errMsg),
        );
        file_put_contents($this->testDir . '/app.log', implode("\n", $lines));

        $result = $this->reader->aggregateUniqueEntries('app.log');

        $this->assertCount(2, $result);
        $byChannel = array_column($result, null, 'channel');
        $this->assertArrayHasKey('order_import', $byChannel);
        $this->assertArrayHasKey('payment', $byChannel);
        $this->assertSame('WARNING', $byChannel['order_import']['level']);
        $this->assertSame('ERROR', $byChannel['payment']['level']);
    }
}
