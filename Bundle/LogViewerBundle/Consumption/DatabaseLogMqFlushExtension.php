<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Consumption;

use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Oro\Component\MessageQueue\Consumption\AbstractExtension;
use Oro\Component\MessageQueue\Consumption\Context;

/**
 * Flushes buffered DB log entries after each MQ message is processed.
 *
 * MQ consumers run in a long-lived process with no kernel.terminate,
 * so this extension ensures deferred log entries reach the database
 * after every message.
 */
class DatabaseLogMqFlushExtension extends AbstractExtension
{
    public function __construct(
        private readonly DatabaseLogHandler $handler,
    ) {
    }

    #[\Override]
    public function onPostReceived(Context $context): void
    {
        $this->handler->flush();
    }
}
