<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Fixtures;

/**
 * Provides test log files for integration tests.
 * Creates sample log files in a test directory.
 */
class LogFileProvider
{
    public function __construct(private readonly string $logDir)
    {
    }

    /**
     * Create sample log files for testing the grid display.
     */
    public function createTestLogFiles(): void
    {
        $this->ensureDirectoryExists();

        $logFiles = [
            'app.log' => $this->generateSampleLogs('Application', 50),
            'security.log' => $this->generateSampleLogs('Security', 30),
            'error.log' => $this->generateSampleLogs('Error', 20),
            'database.log' => $this->generateSampleLogs('Database', 40),
            'cache.log' => $this->generateSampleLogs('Cache', 25),
        ];

        foreach ($logFiles as $filename => $content) {
            file_put_contents($this->logDir . '/' . $filename, $content);
            // Stagger timestamps so files have different modification times
            touch($this->logDir . '/' . $filename, time() - rand(10, 3600));
        }
    }

    /**
     * Create a single test log file with specific content.
     */
    public function createLogFile(string $filename, int $lineCount = 10): void
    {
        $this->ensureDirectoryExists();
        $content = $this->generateSampleLogs(ucfirst($filename), $lineCount);
        file_put_contents($this->logDir . '/' . $filename, $content);
    }

    /**
     * Clean up all test log files.
     */
    public function cleanupTestLogFiles(): void
    {
        if (!is_dir($this->logDir)) {
            return;
        }

        $files = glob($this->logDir . '/*.log');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Generate sample log content with specified number of lines.
     */
    private function generateSampleLogs(string $category, int $lineCount): string
    {
        $lines = [];
        $levels = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'];

        for ($i = 1; $i <= $lineCount; $i++) {
            $level = $levels[array_rand($levels)];
            $timestamp = date('Y-m-d H:i:s', time() - rand(0, 86400));
            $message = sprintf(
                '[%s] [%s] %s: Message #%d - %s',
                $timestamp,
                $level,
                $category,
                $i,
                $this->generateRandomMessage()
            );
            $lines[] = $message;
        }

        return implode("\n", $lines);
    }

    /**
     * Generate random log message.
     */
    private function generateRandomMessage(): string
    {
        $messages = [
            'Request processed successfully',
            'Database connection established',
            'Cache miss for key: user_profile_123',
            'Authentication attempt failed',
            'File upload completed',
            'API endpoint called',
            'Background job started',
            'Memory usage: 512MB',
            'Query execution time: 45ms',
            'User session created',
        ];

        return $messages[array_rand($messages)];
    }

    /**
     * Ensure log directory exists.
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
}
