<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

/**
 * Handles the data-fetching logic for all log-viewer AJAX endpoints.
 *
 * Each method validates nothing — the controller is responsible for validating
 * the filename and checking authorization before calling this service.
 */
class LogViewerAjaxService
{
    public function __construct(private readonly LogFileReader $reader)
    {
    }

    /**
     * @return array{newContent: string, newOffset: int}
     */
    public function liveUpdate(string $fileName, int $offset): array
    {
        return $this->reader->readFromOffset($fileName, $offset);
    }

    /**
     * @return array{content: string, offset: int, lineCount: int, readMs: float, fileSize: string, loadedAt: string}
     */
    public function reload(string $fileName, int $lines): array
    {
        $readStart = microtime(true);
        ['content' => $content, 'offset' => $offset] = $this->reader->readTail($fileName, $lines);
        $readMs = round((microtime(true) - $readStart) * 1000, 1);

        $stat = @stat($this->reader->getFullPath($fileName));

        return [
            'content'   => $content,
            'offset'    => $offset,
            'lineCount' => substr_count($content, "\n") + 1,
            'readMs'    => $readMs,
            'fileSize'  => $stat !== false ? $this->reader->formatBytesPublic($stat['size']) : '?',
            'loadedAt'  => date('H:i:s'),
        ];
    }

    /**
     * @return array{content: string, lineCount: int, readMs: float, mode: string, loadedAt: string}
     */
    public function grep(string $fileName, string $pattern, int $lines, bool $fullScan): array
    {
        $readStart = microtime(true);
        $content   = $this->reader->readGrep($fileName, $pattern, $lines, $fullScan);
        $readMs    = round((microtime(true) - $readStart) * 1000, 1);

        return [
            'content'   => $content,
            'lineCount' => substr_count($content, "\n") + 1,
            'readMs'    => $readMs,
            'mode'      => $fullScan ? 'grep (full file)' : 'grep (last 50 MB)',
            'loadedAt'  => date('H:i:s'),
        ];
    }

    /**
     * @return list<array{class: string, message: string, count: int, firstSeen: ?string, lastSeen: ?string}>
     */
    public function exceptions(string $fileName, int $scanLines): array
    {
        return $this->reader->aggregateExceptions($fileName, $scanLines);
    }

    /**
     * @return list<array{message: string, level: string, channel: string,
     *                    count: int, firstSeen: string, lastSeen: string}>
     */
    public function uniqueEntries(string $fileName, int $scanLines): array
    {
        return $this->reader->aggregateUniqueEntries($fileName, $scanLines);
    }

    /**
     * @return array{labels: string[], values: int[], maxVal: int, totalLines: int}
     */
    public function throughput(string $fileName, int $scanLines): array
    {
        return $this->reader->getThroughput($fileName, $scanLines);
    }

    /**
     * @param  list<string>       $fileNames
     * @param  array<string, int> $offsets
     * @return array{lines: list<array{file: string, text: string}>, offsets: array<string, int>}
     */
    public function multiTail(array $fileNames, array $offsets): array
    {
        return $this->reader->readMultiFromOffset($fileNames, $offsets);
    }

    /**
     * @param  list<string> $fileNames
     * @return array{lines: list<array{file: string, text: string}>, lineCount: int, readMs: float}
     */
    public function multiGrep(array $fileNames, string $pattern, int $limitPerFile, bool $fullScan): array
    {
        return $this->reader->readMultiGrep($fileNames, $pattern, $limitPerFile, $fullScan);
    }
}
