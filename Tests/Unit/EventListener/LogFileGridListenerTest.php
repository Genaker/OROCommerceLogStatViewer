<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Genaker\Bundle\LogViewerBundle\EventListener\LogFileGridListener;
use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface;
use Oro\Bundle\DataGridBundle\Datasource\ArrayDatasource\ArrayDatasource;
use Oro\Bundle\DataGridBundle\Datasource\DatasourceInterface;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use PHPUnit\Framework\TestCase;

class LogFileGridListenerTest extends TestCase
{
    private LogFileGridListener $listener;
    private LogFileReader $reader;

    protected function setUp(): void
    {
        $this->reader   = $this->createMock(LogFileReader::class);
        $this->listener = new LogFileGridListener($this->reader);
    }

    /** @test */
    public function testOnBuildAfterPopulatesArrayDatasource(): void
    {
        $files = [
            ['file_name' => 'app.log',   'size' => 1024, 'modified' => 1700000002],
            ['file_name' => 'debug.log', 'size' => 512,  'modified' => 1700000001],
        ];

        $this->reader->expects($this->once())
            ->method('getLogFiles')
            ->willReturn($files);

        $datasource = new ArrayDatasource();
        $datagrid   = $this->createConfiguredMock(DatagridInterface::class, [
            'getName'       => 'genaker_log_files_grid',
            'getDatasource' => $datasource,
        ]);

        $event = new BuildAfter($datagrid);
        $this->listener->onBuildAfter($event);

        $this->assertSame($files, $datasource->getArraySource());
    }

    /** @test */
    public function testOnBuildAfterSkipsNonArrayDatasource(): void
    {
        $this->reader->expects($this->never())
            ->method('getLogFiles');

        $datasource = $this->createMock(DatasourceInterface::class);
        $datagrid   = $this->createConfiguredMock(DatagridInterface::class, [
            'getName'       => 'genaker_log_files_grid',
            'getDatasource' => $datasource,
        ]);

        $event = new BuildAfter($datagrid);
        $this->listener->onBuildAfter($event);
    }

    /** @test */
    public function testOnBuildAfterSkipsWhenGridNameDoesNotMatch(): void
    {
        $this->reader->expects($this->never())
            ->method('getLogFiles');

        $datasource = new ArrayDatasource();
        $datagrid   = $this->createConfiguredMock(DatagridInterface::class, [
            'getName'       => 'some_other_grid',
            'getDatasource' => $datasource,
        ]);

        $event = new BuildAfter($datagrid);
        $this->listener->onBuildAfter($event);

        $this->assertSame([], $datasource->getArraySource());
    }

    /** @test */
    public function testOnBuildAfterSetsEmptyArrayWhenNoFiles(): void
    {
        $this->reader->expects($this->once())
            ->method('getLogFiles')
            ->willReturn([]);

        $datasource = new ArrayDatasource();
        $datagrid   = $this->createConfiguredMock(DatagridInterface::class, [
            'getName'       => 'genaker_log_files_grid',
            'getDatasource' => $datasource,
        ]);

        $event = new BuildAfter($datagrid);
        $this->listener->onBuildAfter($event);

        $this->assertSame([], $datasource->getArraySource());
    }
}
