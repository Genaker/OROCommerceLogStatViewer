<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

/**
 * Measures CLI command wall-clock time and records it into genaker_http_performance.
 *
 * Fires on console.command (start) and console.terminate (end), both of which
 * run in the same process so a simple float property is safe for timing.
 */
class ConsolePerformanceListener
{
    private float $startTime = 0.0;

    public function __construct(
        private readonly HttpPerformanceRecorder $recorder,
    ) {
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->startTime = microtime(true);
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->startTime === 0.0) {
            return;
        }

        $name        = $event->getCommand()->getName() ?? 'unknown';
        $responseMs  = (microtime(true) - $this->startTime) * 1000.0;
        $this->startTime = 0.0;

        $this->recorder->record($name, HttpPerformance::TYPE_CLI, $responseMs);
    }
}
