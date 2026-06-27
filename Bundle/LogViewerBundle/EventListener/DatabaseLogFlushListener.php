<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Flushes buffered log entries to the database after work is done:
 *  - kernel.terminate  (HTTP — after response sent)
 *  - console.terminate (CLI — after command finishes)
 *
 * MQ consumers are handled by DatabaseLogMqFlushExtension.
 */
class DatabaseLogFlushListener
{
    public function __construct(
        private readonly DatabaseLogHandler $handler,
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->handler->flush();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->handler->flush();
    }
}
