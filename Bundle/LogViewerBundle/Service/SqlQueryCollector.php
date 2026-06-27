<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Doctrine\DBAL\Logging\DebugStack;

/**
 * Extends DBAL DebugStack to capture per-query caller information and
 * detect N+1 / slow-query issues at kernel.terminate time.
 *
 * Designed for zero overhead when SQL tracking is disabled: the collector
 * is never attached to the DBAL configuration in that case.
 */
class SqlQueryCollector extends DebugStack
{
    private const int MAX_SAMPLES = 500;

    /** Namespaces whose frames are skipped when extracting the application caller. */
    private const array SKIP_PREFIXES = [
        'Doctrine\\',
        'Symfony\\Component\\HttpKernel\\',
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\Console\\',
        'Genaker\\Bundle\\LogViewerBundle\\Service\\SqlQuery',
        'Genaker\\Bundle\\LogViewerBundle\\EventListener\\SqlPerformance',
    ];

    #[\Override]
    public function stopQuery(): void
    {
        parent::stopQuery();

        if (count($this->queries) > self::MAX_SAMPLES) {
            unset($this->queries[$this->currentQuery]);
            $this->currentQuery--;
            return;
        }

        $this->queries[$this->currentQuery]['caller'] = $this->extractCaller();
    }

    /**
     * Analyses buffered queries, detects N+1 and slow-query issues, clears buffer.
     *
     * @return array<int, array{
     *     template: string,
     *     isN1: bool,
     *     isSlow: bool,
     *     executionCount: int,
     *     worstN1Count: int|null,
     *     worstSlowMs: float|null,
     *     caller: string|null,
     *     params: array<mixed>|null,
     *     url: string|null,
     *     suggestion: string|null,
     *     analysisData: array<string, mixed>
     * }>
     */
    public function getIssues(string $url, int $n1Threshold, float $slowMs): array
    {
        $grouped = $this->groupByTemplate();
        $issues  = [];

        foreach ($grouped as $template => $group) {
            $isN1   = $group['count'] > $n1Threshold;
            $isSlow = $group['maxMs'] > $slowMs;

            if (!$isN1 && !$isSlow) {
                continue;
            }

            $issues[] = [
                'template'      => (string) $template,
                'isN1'          => $isN1,
                'isSlow'        => $isSlow,
                'executionCount' => $group['count'],
                'worstN1Count'  => $isN1 ? $group['count'] : null,
                'worstSlowMs'   => $isSlow ? ($isN1 ? round($group['totalMs'], 1) : $group['maxMs']) : null,
                'caller'        => $group['maxCaller'],
                'params'        => $group['maxParams'],
                'url'           => $url,
                'suggestion'    => $this->buildSuggestion($group, $isN1, $isSlow),
                'analysisData'  => $this->buildAnalysisData($group),
            ];
        }

        $this->queries      = [];
        $this->currentQuery = 0;

        return $issues;
    }

    /**
     * @return array<string, array{count: int, maxMs: float, minMs: float, totalMs: float, maxCaller: string|null, maxParams: array<mixed>|null, paramHashes: array<string, int>}>
     */
    private function groupByTemplate(): array
    {
        $grouped = [];

        foreach ($this->queries as $query) {
            $template  = $this->normalizeSql((string) ($query['sql'] ?? ''));
            $ms        = round((float) ($query['executionMS'] ?? 0.0) * 1000.0, 1);
            $caller    = $query['caller'] ?? null;
            $params    = $this->maskSensitiveParams($query['params'] ?? null);
            $paramHash = md5(serialize($params));

            if (!isset($grouped[$template])) {
                $grouped[$template] = [
                    'count'       => 1,
                    'maxMs'       => $ms,
                    'minMs'       => $ms,
                    'totalMs'     => $ms,
                    'maxCaller'   => $caller,
                    'maxParams'   => $params,
                    'paramHashes' => [$paramHash => 1],
                ];
                continue;
            }

            $grouped[$template]['count']++;
            $grouped[$template]['totalMs'] += $ms;
            $grouped[$template]['paramHashes'][$paramHash] = ($grouped[$template]['paramHashes'][$paramHash] ?? 0) + 1;

            if ($ms < $grouped[$template]['minMs']) {
                $grouped[$template]['minMs'] = $ms;
            }

            if ($ms > $grouped[$template]['maxMs']) {
                $grouped[$template]['maxMs']     = $ms;
                $grouped[$template]['maxCaller'] = $caller;
                $grouped[$template]['maxParams'] = $params;
            }
        }

        return $grouped;
    }

    /**
     * Mask values whose associative key contains a sensitive keyword.
     * Positional (non-string-keyed) arrays are returned unchanged because
     * positional parameters carry no key-name context.
     *
     * @param  array<mixed>|null $params
     * @return array<mixed>|null
     */
    public function maskSensitiveParams(?array $params): ?array
    {
        if ($params === null || $params === []) {
            return $params;
        }

        $masked = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $masked[$key] = '***';
            } else {
                $masked[$key] = is_array($value) ? $this->maskSensitiveParams($value) : $value;
            }
        }

        return $masked;
    }

    /** @param array<string, mixed> $group */
    private function buildSuggestion(array $group, bool $isN1, bool $isSlow): string
    {
        $uniqueParamSets   = count($group['paramHashes']);
        $maxSameParams     = $uniqueParamSets > 0 ? max($group['paramHashes']) : 1;
        $suggestions       = [];

        if ($isN1) {
            if ($uniqueParamSets === 1) {
                $suggestions[] = 'Identical query repeated ' . $group['count']
                    . 'x with same params — cache result or use identity map (e.g. find() from already-loaded entity).';
            } elseif ($maxSameParams > 1) {
                $suggestions[] = 'N+1 with ' . $uniqueParamSets . ' unique param sets ('
                    . $group['count'] . ' total calls, up to ' . $maxSameParams
                    . ' duplicates) — consider partial caching and batching remaining into IN clause.';
            } else {
                $suggestions[] = 'N+1: ' . $group['count'] . ' queries with ' . $uniqueParamSets
                    . ' unique param sets — batch into one SELECT ... WHERE id IN (?).';
            }
        }

        if ($isSlow) {
            $avgMs = round($group['totalMs'] / $group['count'], 1);
            if ($isN1) {
                $suggestions[] = 'Total cost ' . round($group['totalMs'], 1) . 'ms across '
                    . $group['count'] . ' calls (avg ' . $avgMs . 'ms) — fix N+1 first, then review index coverage.';
            } else {
                $suggestions[] = 'Slow query: max ' . $group['maxMs'] . 'ms, avg '
                    . $avgMs . 'ms — review EXPLAIN output in analysis_data for seq scan vs index scan.';
            }
        }

        return implode(' ', $suggestions);
    }

    /** @param array<string, mixed> $group */
    private function buildAnalysisData(array $group): array
    {
        $uniqueParamSets = count($group['paramHashes']);
        $maxSameParams   = $uniqueParamSets > 0 ? max($group['paramHashes']) : 1;

        return [
            'executions'       => $group['count'],
            'uniqueParamSets'  => $uniqueParamSets,
            'sameParamsRepeats' => $maxSameParams,
            'allParamsIdentical' => $uniqueParamSets === 1 && $group['count'] > 1,
            'avgMs'            => round($group['totalMs'] / $group['count'], 1),
            'minMs'            => $group['minMs'],
            'maxMs'            => $group['maxMs'],
            'totalMs'          => round($group['totalMs'], 1),
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (['password', 'passwd', 'secret', 'token', 'auth', 'apikey', 'api_key', 'credential', 'private_key', 'access_key'] as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSql(string $sql): string
    {
        // Strip single-line comments
        $normalized = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
        // Strip multi-line comments
        $normalized = preg_replace('/\/\*.*?\*\//s', '', $normalized) ?? $normalized;
        // Replace string literals
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $normalized) ?? $normalized;
        // Replace numeric literals (not inside identifiers)
        $normalized = preg_replace('/\b\d+(\.\d+)?\b/', '?', $normalized) ?? $normalized;
        // Collapse repeated ? in IN clauses
        $normalized = preg_replace('/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized) ?? $normalized;
        // Normalize whitespace
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        return mb_substr($normalized, 0, 1000);
    }

    private function extractCaller(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($frames as $frame) {
            $class = $frame['class'] ?? '';

            if (empty($class)) {
                continue;
            }

            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    continue 2;
                }
            }

            $method = $frame['function'] ?? '';
            return $class . '::' . $method;
        }

        return null;
    }
}
