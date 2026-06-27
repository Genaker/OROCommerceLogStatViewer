<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use Oro\Bundle\DataGridBundle\Datasource\ArrayDatasource\ArrayDatasource;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;

/**
 * Populates the log file grid with available log files from the log directory.
 */
class LogFileGridListener
{
    public function __construct(private readonly LogFileReader $reader)
    {
    }

    public function onBuildAfter(BuildAfter $event): void
    {
        if ($event->getDatagrid()->getName() !== 'genaker_log_files_grid') {
            return;
        }

        $datasource = $event->getDatagrid()->getDatasource();

        if (!$datasource instanceof ArrayDatasource) {
            return;
        }

        $files = $this->reader->getLogFiles();
        $datasource->setArraySource($files);
    }
}
