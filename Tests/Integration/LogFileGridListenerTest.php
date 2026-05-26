<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Integration;

use Genaker\Bundle\LogViewerBundle\EventListener\LogFileGridListener;
use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use Oro\Bundle\DataGridBundle\Datasource\ArrayDatasource\ArrayDatasource;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Integration test for LogFileGridListener with real log files.
 */
class LogFileGridListenerTest extends WebTestCase
{
    private string $testLogDir;
    private LogFileReader $reader;
    private LogFileGridListener $listener;

    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . '/log_viewer_test_' . uniqid();
        mkdir($this->testLogDir, 0755, true);

        $this->reader = new LogFileReader($this->testLogDir);
        $this->listener = new LogFileGridListener($this->reader);
    }

    protected function tearDown(): void
    {
        // Clean up test log files
        if (is_dir($this->testLogDir)) {
            $finder = new Finder();
            foreach ($finder->files()->in($this->testLogDir) as $file) {
                unlink((string)$file);
            }
            rmdir($this->testLogDir);
        }
    }

    public function testGetLogFilesReturnsEmptyArrayWhenNologsExist(): void
    {
        $files = $this->reader->getLogFiles();
        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testGetLogFilesReturnsSingleLogFile(): void
    {
        // Create a test log file
        $logContent = "Test log content\nLine 2\nLine 3";
        file_put_contents($this->testLogDir . '/test.log', $logContent);

        $files = $this->reader->getLogFiles();

        $this->assertCount(1, $files);
        $this->assertArrayHasKey('file_name', $files[0]);
        $this->assertArrayHasKey('size', $files[0]);
        $this->assertArrayHasKey('modified', $files[0]);
        $this->assertEquals('test.log', $files[0]['file_name']);
        $this->assertGreaterThan(0, $files[0]['size']);
    }

    public function testGetLogFilesReturnsMultipleFiles(): void
    {
        // Create multiple test log files
        file_put_contents($this->testLogDir . '/app.log', 'App logs');
        sleep(1); // Ensure different timestamps
        file_put_contents($this->testLogDir . '/security.log', 'Security logs');
        sleep(1);
        file_put_contents($this->testLogDir . '/error.log', 'Error logs');

        $files = $this->reader->getLogFiles();

        $this->assertCount(3, $files);
        $fileNames = array_column($files, 'file_name');
        $this->assertContains('app.log', $fileNames);
        $this->assertContains('security.log', $fileNames);
        $this->assertContains('error.log', $fileNames);
    }

    public function testGetLogFilesOrderedByModificationTimeNewestFirst(): void
    {
        // Create files with known timestamps
        file_put_contents($this->testLogDir . '/oldest.log', 'Oldest');
        sleep(1);
        file_put_contents($this->testLogDir . '/middle.log', 'Middle');
        sleep(1);
        file_put_contents($this->testLogDir . '/newest.log', 'Newest');

        $files = $this->reader->getLogFiles();

        $this->assertCount(3, $files);
        // Should be ordered newest first
        $this->assertEquals('newest.log', $files[0]['file_name']);
        $this->assertEquals('middle.log', $files[1]['file_name']);
        $this->assertEquals('oldest.log', $files[2]['file_name']);
    }

    public function testIgnoresNonLogFiles(): void
    {
        file_put_contents($this->testLogDir . '/app.log', 'Log content');
        file_put_contents($this->testLogDir . '/readme.txt', 'Not a log');
        file_put_contents($this->testLogDir . '/data.json', '{}');

        $files = $this->reader->getLogFiles();

        $this->assertCount(1, $files);
        $this->assertEquals('app.log', $files[0]['file_name']);
    }

    public function testGridListenerPopulatesDataSourceWithLogFiles(): void
    {
        // Create test log files
        file_put_contents($this->testLogDir . '/test1.log', 'Log 1');
        file_put_contents($this->testLogDir . '/test2.log', 'Log 2');

        // Create mock event with ArrayDatasource
        $datasource = new ArrayDatasource();
        $dataGrid = $this->createMock(\Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface::class);
        $dataGrid->method('getName')->willReturn('genaker_log_files_grid');
        $dataGrid->method('getDatasource')->willReturn($datasource);

        $event = new BuildAfter($dataGrid);

        // Listener should populate the datasource
        $this->listener->onBuildAfter($event);

        // Verify datasource was populated
        $arraySource = $datasource->getArraySource();
        $this->assertIsArray($arraySource);
        $this->assertCount(2, $arraySource);
    }

    public function testGridListenerIgnoresOtherGrids(): void
    {
        // Create test log files
        file_put_contents($this->testLogDir . '/test1.log', 'Log 1');

        // Create mock event for a different grid
        $datasource = new ArrayDatasource();
        $dataGrid = $this->createMock(\Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface::class);
        $dataGrid->method('getName')->willReturn('some_other_grid');
        $dataGrid->method('getDatasource')->willReturn($datasource);

        $event = new BuildAfter($dataGrid);

        // Listener should ignore this grid
        $this->listener->onBuildAfter($event);

        // Verify datasource was NOT populated
        $arraySource = $datasource->getArraySource();
        $this->assertEmpty($arraySource);
    }
}
