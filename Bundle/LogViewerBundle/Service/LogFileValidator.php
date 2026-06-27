<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

/**
 * Validates log file paths to prevent directory traversal and ensure only .log files are accessed.
 */
class LogFileValidator
{
    public function __construct(private readonly string $logDir)
    {
    }

    public function validate(string $fileName): void
    {
        $resolved = realpath($this->logDir . '/' . $fileName);

        if ($resolved === false || !str_starts_with($resolved, realpath($this->logDir))) {
            throw new \InvalidArgumentException(sprintf('Invalid log file path: "%s".', $fileName));
        }

        if (pathinfo($resolved, PATHINFO_EXTENSION) !== 'log') {
            throw new \InvalidArgumentException('Only .log files are allowed.');
        }
    }
}
