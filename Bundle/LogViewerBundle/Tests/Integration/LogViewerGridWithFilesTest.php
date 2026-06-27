<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Integration;

use Genaker\Bundle\LogViewerBundle\EventListener\LogFileGridListener;
use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use Genaker\Bundle\LogViewerBundle\Tests\Fixtures\LogFileProvider;
use Oro\Bundle\DataGridBundle\Datasource\ArrayDatasource\ArrayDatasource;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Integration test: Verify log files appear in the grid.
 */
class LogViewerGridWithFilesTest extends WebTestCase
{
    private string $testLogDir;
    private LogFileReader $reader;
    private LogFileGridListener $listener;
    private LogFileProvider $provider;

    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . '/log_grid_test_' . uniqid();
        mkdir($this->testLogDir, 0755, true);

        $this->reader = new LogFileReader($this->testLogDir);
        $this->listener = new LogFileGridListener($this->reader);
        $this->provider = new LogFileProvider($this->testLogDir);
    }

    protected function tearDown(): void
    {
        $this->provider->cleanupTestLogFiles();
        if (is_dir($this->testLogDir)) {
            rmdir($this->testLogDir);
        }
    }

    public function testGridDisplaysLogFilesWhenPresent(): void
    {
        // Create test log files using provider
        $this->provider->createTestLogFiles();

        // Get files from reader
        $files = $this->reader->getLogFiles();

        // Verify files were created and detected
        $this->assertGreaterThan(0, count($files), 'No log files detected');
        $this->assertNotEmpty($files);

        // Verify file structure
        foreach ($files as $file) {
            $this->assertArrayHasKey('file_name', $file);
            $this->assertArrayHasKey('size', $file);
            $this->assertArrayHasKey('modified', $file);
            $this->assertStringEndsWith('.log', $file['file_name']);
            $this->assertGreaterThan(0, $file['size']);
        }
    }

    public function testGridListenerPopulatesWithMultipleFiles(): void
    {
        // Create multiple test log files
        $this->provider->createTestLogFiles();

        // Create mock event
        $datasource = new ArrayDatasource();
        $dataGrid = $this->createMock(\Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface::class);
        $dataGrid->method('getName')->willReturn('genaker_log_files_grid');
        $dataGrid->method('getDatasource')->willReturn($datasource);

        $event = new BuildAfter($dataGrid);

        // Listener should populate data source
        $this->listener->onBuildAfter($event);

        // Verify datasource contains files
        $arraySource = $datasource->getArraySource();
        $this->assertIsArray($arraySource);
        $this->assertGreaterThan(0, count($arraySource));

        // Verify each row has required columns
        foreach ($arraySource as $row) {
            $this->assertArrayHasKey('file_name', $row);
            $this->assertArrayHasKey('size', $row);
            $this->assertArrayHasKey('modified', $row);
        }
    }

    public function testGridDisplaysFilesWithCorrectSize(): void
    {
        // Create a log file with known size
        $this->provider->createLogFile('test.log', 100);

        $files = $this->reader->getLogFiles();

        $this->assertCount(1, $files);
        $file = $files[0];
        $this->assertEquals('test.log', $file['file_name']);
        $this->assertGreaterThan(0, $file['size']);
    }

    public function testGridDisplaysFilesSortedByModificationTime(): void
    {
        // Create files in specific order
        $this->provider->createLogFile('oldest.log', 10);
        sleep(1);
        $this->provider->createLogFile('middle.log', 10);
        sleep(1);
        $this->provider->createLogFile('newest.log', 10);

        $files = $this->reader->getLogFiles();

        $this->assertCount(3, $files);
        // Should be newest first
        $this->assertEquals('newest.log', $files[0]['file_name']);
        $this->assertEquals('middle.log', $files[1]['file_name']);
        $this->assertEquals('oldest.log', $files[2]['file_name']);
    }

    public function testEmptyGridWhenNoLogsExist(): void
    {
        // Don't create any files
        $files = $this->reader->getLogFiles();

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }
}
