<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

/**
 * Reads and retrieves log files from the configured log directory.
 */
class LogFileReader
{
    public function __construct(
        private readonly string $logDir,
        private readonly int $grepScanLimitBytes = 52428800, // 50 MB
        private readonly int $grepTimeoutSeconds = 10,
    ) {
    }

    public function getFullPath(string $fileName): string
    {
        return $this->logDir . '/' . $fileName;
    }

    /**
     * Returns the last $lines lines of a file plus the current file size as byte offset.
     *
     * @return array{content: string, offset: int}
     */
    public function readTail(string $fileName, int $lines = 100): array
    {
        $path = $this->getFullPath($fileName);
        $size = (int) @filesize($path);

        $content = '';
        if ($size > 0) {
            $fh      = fopen($path, 'rb');
            $chunk   = min($size, 65536); // read last 64KB max
            fseek($fh, -$chunk, SEEK_END);
            $buffer  = fread($fh, $chunk);
            fclose($fh);

            $allLines = explode("\n", (string) $buffer);
            $tail     = array_slice($allLines, -$lines);
            $content  = implode("\n", $tail);
        }

        return [
            'content' => $content,
            'offset'  => $size,
        ];
    }

    /**
     * Reads bytes appended to the file since $offset.
     *
     * @return array{newContent: string, newOffset: int}
     */
    public function readFromOffset(string $fileName, int $offset): array
    {
        $path = $this->getFullPath($fileName);
        $size = (int) filesize($path);

        if ($size <= $offset) {
            return ['newContent' => '', 'newOffset' => $offset];
        }

        $fh      = fopen($path, 'rb');
        fseek($fh, $offset);
        $content = fread($fh, $size - $offset);
        fclose($fh);

        return ['newContent' => (string) $content, 'newOffset' => $size];
    }

    /**
     * Returns last $limit lines matching $pattern using grep.
     *
     * By default scans only the last 50 MB of the file (instant even on 4 GB logs).
     * Pass $fullScan=true to search the entire file.
     */
    public function readGrep(string $fileName, string $pattern, int $limit = 500, bool $fullScan = false): string
    {
        $path      = escapeshellarg($this->getFullPath($fileName));
        $pattern   = escapeshellarg($pattern);
        $limit     = (int) $limit;
        $timeout   = (int) $this->grepTimeoutSeconds;
        $scanLimit = (int) $this->grepScanLimitBytes;

        if ($fullScan) {
            $cmd = "timeout {$timeout} grep -a -- {$pattern} {$path} | tail -n {$limit} 2>/dev/null";
        } else {
            $cmd = "tail -c {$scanLimit} {$path}"
                . " | timeout {$timeout} grep -a -- {$pattern}"
                . " | tail -n {$limit} 2>/dev/null";
        }

        return shell_exec($cmd) ?? '';
    }

    /**
     * Returns metadata for all *.log files under $logDir, newest-first.
     * File size is read via stat() — O(1), does not read file contents.
     *
     * @return list<array{file_name: string, size: string, modified: string}>
     */
    public function getLogFiles(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $paths = glob($this->logDir . '/*.log');
        if ($paths === false || $paths === []) {
            return [];
        }

        $files = [];
        foreach ($paths as $path) {
            $stat = @stat($path);
            if ($stat === false) {
                continue;
            }
            $files[] = [
                'file_name' => basename($path),
                'size'      => $this->formatBytes($stat['size']),
                'modified'  => date('Y-m-d H:i:s', $stat['mtime']),
                'mtime'     => $stat['mtime'],
            ];
        }

        usort($files, static fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return $files;
    }

    /**
     * Aggregates unique exceptions from the last $scanLines lines.
     *
     * Each group contains: class, message, count, firstSeen, lastSeen.
     * Returns up to 50 groups sorted by count descending.
     */
    public function aggregateExceptions(string $fileName, int $scanLines = 10000): array
    {
        $path = escapeshellarg($this->getFullPath($fileName));
        $raw  = shell_exec('tail -n ' . (int) $scanLines . ' ' . $path . ' 2>/dev/null') ?? '';

        if ($raw === '') {
            return [];
        }

        $groups          = [];
        $currentDatetime = null;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $tsm)) {
                $currentDatetime = $tsm[1];
            }

            // Match Monolog-serialized exceptions: [object] (ClassName(code: N): message at ...)
            // Supports namespaced classes: My\Namespace\FooException
            if (!preg_match(
                '/\[object\]\s*\(((?:[\w\\\\]+\\\\)*\w+(?:Exception|Error|Throwable))'
                . '(?:\(code:[^)]*\))?:\s*(.{0,200}?)'
                . '(?:\s+at\s+\S+|\s+in\s+\S+|\\\\n|$)/u',
                $line,
                $em
            )) {
                // Fallback: bare "ClassName: message" pattern on .ERROR/.CRITICAL lines
                if (!preg_match(
                    '/\.(?:ERROR|CRITICAL).*?((?:[\w\\\\]+\\\\)*\w+(?:Exception|Error|Throwable))'
                    . '(?:\(code:[^)]*\))?[:\s]+(.{0,160}?)(?:\s*\{|$)/u',
                    $line,
                    $em
                )) {
                    continue;
                }
            }

            $exClass = $em[1];
            $exMsg   = rtrim(trim($em[2]), '"\'');

            // Normalise for grouping: strip object IDs, memory addresses and line numbers
            $normalised = preg_replace(
                ['/\b[0-9a-f]{6,}\b/i', '/\(\d+\)/', '/\bat\s+\S+:\d+/', '/\bin\s+\S+:\d+/', '/line\s+\d+/i'],
                '',
                $exMsg
            ) ?? $exMsg;
            $key = $exClass . ':' . trim((string) preg_replace('/\s+/', ' ', $normalised));

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'class'     => $exClass,
                    'message'   => $exMsg,
                    'count'     => 0,
                    'firstSeen' => $currentDatetime,
                    'lastSeen'  => $currentDatetime,
                ];
            }
            $groups[$key]['count']++;
            if ($currentDatetime !== null) {
                $groups[$key]['lastSeen'] = $currentDatetime;
            }
        }

        usort($groups, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return array_values(array_slice($groups, 0, 50));
    }

    /**
     * Aggregates unique log entries by stripping the ISO timestamp and grouping
     * identical messages, regardless of when they occurred.
     *
     * Each entry in the returned array contains:
     *   - message   : the log line with timestamp removed
     *   - level     : Monolog level extracted from the line (WARNING, ERROR, etc.)
     *   - channel   : Monolog channel (e.g. "order_import")
     *   - count     : number of occurrences
     *   - firstSeen : ISO timestamp of the first occurrence
     *   - lastSeen  : ISO timestamp of the most recent occurrence
     *
     * @return list<array{
     *   message: string, level: string, channel: string,
     *   count: int, firstSeen: string, lastSeen: string
     * }>
     */
    public function aggregateUniqueEntries(string $fileName, int $scanLines = 10000): array
    {
        $path = escapeshellarg($this->getFullPath($fileName));
        $raw  = shell_exec('tail -n ' . (int) $scanLines . ' ' . $path . ' 2>/dev/null') ?? '';

        if ($raw === '') {
            return [];
        }

        $groups = [];

        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            // Extract and strip ISO timestamp: [2026-05-22T15:31:01.719807+00:00]
            $timestamp = null;
            $stripped  = $line;
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s*/', $line, $tsm)) {
                $timestamp = $tsm[1];
                $stripped  = substr($line, strlen($tsm[0]));
            }

            if ($stripped === '') {
                continue;
            }

            // Extract channel and level from "channel.LEVEL: " prefix
            $channel = '';
            $level   = '';
            if (preg_match('/^([a-zA-Z0-9_\-]+)\.([A-Z]+):\s/', $stripped, $clm)) {
                $channel = $clm[1];
                $level   = $clm[2];
            }

            // Normalise key: collapse whitespace
            $key = (string) preg_replace('/\s+/', ' ', $stripped);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'message'   => $stripped,
                    'level'     => $level,
                    'channel'   => $channel,
                    'count'     => 0,
                    'firstSeen' => $timestamp ?? '',
                    'lastSeen'  => $timestamp ?? '',
                ];
            }
            $groups[$key]['count']++;
            if ($timestamp !== null) {
                $groups[$key]['lastSeen'] = $timestamp;
            }
        }

        // Only include lines that appear more than once
        $groups = array_filter($groups, static fn (array $g) => $g['count'] > 1);

        usort($groups, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return array_values(array_slice($groups, 0, 200));
    }

    /**
     * Counts log lines per minute for the last $scanLines lines (Monolog timestamps).
     *
     * Returns {labels: string[], values: int[], maxVal: int, totalLines: int}
     */
    public function getThroughput(string $fileName, int $scanLines = 5000): array
    {
        $path = escapeshellarg($this->getFullPath($fileName));
        $raw  = shell_exec('tail -n ' . (int) $scanLines . ' ' . $path . ' 2>/dev/null') ?? '';

        if ($raw === '') {
            return ['labels' => [], 'values' => [], 'maxVal' => 0, 'totalLines' => 0];
        }

        $buckets    = [];
        $totalLines = 0;

        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $totalLines++;
            if (preg_match('/^\[\d{4}-\d{2}-\d{2} (\d{2}:\d{2})/', $line, $m)) {
                $minute          = $m[1];
                $buckets[$minute] = ($buckets[$minute] ?? 0) + 1;
            }
        }

        ksort($buckets);

        if (count($buckets) > 60) {
            $buckets = array_slice($buckets, -60, 60, true);
        }

        $values = array_values($buckets);

        return [
            'labels'     => array_keys($buckets),
            'values'     => $values,
            'maxVal'     => $values !== [] ? (int) max($values) : 0,
            'totalLines' => $totalLines,
        ];
    }

    /**
     * Reads new content from multiple log files since their last known byte offsets.
     *
     * Returns combined lines tagged with source file name, plus updated offsets for each file.
     *
     * @param  list<string>          $fileNames   Validated file names to read
     * @param  array<string, int>    $offsets     Map of fileName => last known byte offset
     * @return array{lines: list<array{file: string, text: string}>, offsets: array<string, int>}
     */
    public function readMultiFromOffset(array $fileNames, array $offsets): array
    {
        $combinedLines = [];
        $newOffsets    = [];

        foreach ($fileNames as $fileName) {
            $previousOffset = isset($offsets[$fileName]) ? (int) $offsets[$fileName] : 0;
            $result         = $this->readFromOffset($fileName, $previousOffset);

            $newOffsets[$fileName] = $result['newOffset'];

            if ($result['newContent'] === '') {
                continue;
            }

            $rawLines = explode("\n", rtrim($result['newContent'], "\n"));
            foreach ($rawLines as $lineText) {
                $combinedLines[] = ['file' => $fileName, 'text' => $lineText];
            }
        }

        return ['lines' => $combinedLines, 'offsets' => $newOffsets];
    }

    /**
     * Greps multiple log files for a pattern and returns combined tagged lines.
     *
     * Each returned entry carries the source file name so the UI can display it.
     * Results per file are limited to $limitPerFile lines; the combined result
     * is further capped at 2 000 entries to protect the browser.
     *
     * @param  list<string> $fileNames    Validated file names to search
     * @param  string       $pattern      Grep pattern
     * @param  int          $limitPerFile Max matching lines per file
     * @param  bool         $fullScan     When true, search entire file; otherwise last 50 MB
     * @return array{lines: list<array{file: string, text: string}>, lineCount: int, readMs: float}
     */
    public function readMultiGrep(
        array $fileNames,
        string $pattern,
        int $limitPerFile = 500,
        bool $fullScan = false
    ): array {
        $combinedLines = [];
        $readStart     = microtime(true);

        foreach ($fileNames as $fileName) {
            $raw = $this->readGrep($fileName, $pattern, $limitPerFile, $fullScan);
            if ($raw === '') {
                continue;
            }
            $rawLines = explode("\n", rtrim($raw, "\n"));
            foreach ($rawLines as $lineText) {
                $combinedLines[] = ['file' => $fileName, 'text' => $lineText];
            }
        }

        // Safety cap: never return more than 2 000 lines to the browser
        if (count($combinedLines) > 2000) {
            $combinedLines = array_slice($combinedLines, -2000);
        }

        return [
            'lines'     => $combinedLines,
            'lineCount' => count($combinedLines),
            'readMs'    => round((microtime(true) - $readStart) * 1000, 1),
        ];
    }

    public function formatBytesPublic(int $bytes): string
    {
        return $this->formatBytes($bytes);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
