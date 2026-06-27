<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Runs PostgreSQL EXPLAIN (FORMAT JSON) on a normalised SQL template.
 *
 * Uses ANALYZE FALSE so the query is never actually executed — only the
 * planner's cost estimate and node type are returned.
 *
 * Parameter placeholders (?) are replaced with NULL so the query is valid
 * PostgreSQL syntax. Plans may differ from real executions when param
 * selectivity matters, but seq-scan vs index-scan detection is reliable.
 *
 * Errors are silently swallowed — explain data is best-effort.
 */
class SqlExplainRunner
{
    /** Maximum number of EXPLAIN calls per flush to limit overhead. */
    private const int MAX_EXPLAINS_PER_FLUSH = 3;

    private int $explainCount = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Reset the per-flush counter.
     * Must be called at the start of each flush() invocation.
     */
    public function resetCounter(): void
    {
        $this->explainCount = 0;
    }

    /**
     * Run EXPLAIN on the normalised template and return a structured summary.
     * Returns null when the limit is reached, the SQL is not a SELECT, or any
     * error occurs.
     *
     * @return array<string, mixed>|null
     */
    public function explain(string $normalizedTemplate, ?array $lastParams): ?array
    {
        if ($this->explainCount >= self::MAX_EXPLAINS_PER_FLUSH) {
            return null;
        }

        if (!$this->isSelectQuery($normalizedTemplate)) {
            return null;
        }

        try {
            $sql          = $this->substituteParams($normalizedTemplate, $lastParams);
            $explainSql   = 'EXPLAIN (FORMAT JSON, ANALYZE FALSE, COSTS TRUE, VERBOSE FALSE) ' . $sql;
            $result       = $this->connection->executeQuery($explainSql)->fetchOne();
            $plan         = json_decode((string) $result, true);
            $this->explainCount++;
            return $this->summarisePlan($plan);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSelectQuery(string $sql): bool
    {
        return stripos(ltrim($sql), 'SELECT') === 0;
    }

    /**
     * Replace ? placeholders sequentially with actual param values (safely
     * quoted) or NULL when params are unavailable.
     */
    private function substituteParams(string $sql, ?array $lastParams): string
    {
        if ($lastParams === null || $lastParams === []) {
            return str_replace('?', 'NULL', $sql);
        }

        $values = array_values($lastParams);
        $index  = 0;

        return (string) preg_replace_callback('/\?/', function () use ($values, &$index): string {
            if (!isset($values[$index])) {
                $index++;
                return 'NULL';
            }

            $value = $values[$index++];

            if ($value === null) {
                return 'NULL';
            }

            if (is_bool($value)) {
                return $value ? 'TRUE' : 'FALSE';
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            // String: escape single quotes
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $sql);
    }

    /**
     * Extract the useful parts of a PostgreSQL JSON EXPLAIN plan.
     *
     * @param  array<mixed>|null $plan
     * @return array<string, mixed>
     */
    private function summarisePlan(?array $plan): array
    {
        if ($plan === null || !isset($plan[0]['Plan'])) {
            return [];
        }

        $rootNode = $plan[0]['Plan'];

        return [
            'nodeType'       => $rootNode['Node Type'] ?? null,
            'totalCost'      => $rootNode['Total Cost'] ?? null,
            'startupCost'    => $rootNode['Startup Cost'] ?? null,
            'planRows'       => $rootNode['Plan Rows'] ?? null,
            'indexName'      => $rootNode['Index Name'] ?? null,
            'indexCond'      => $rootNode['Index Cond'] ?? null,
            'filterCond'     => $rootNode['Filter'] ?? null,
            'scanType'       => $this->classifyScanType($rootNode),
            'allNodeTypes'   => $this->collectNodeTypes($rootNode),
            'indexesUsed'    => $this->collectIndexes($rootNode),
        ];
    }

    /** @param array<string, mixed> $node */
    private function classifyScanType(array $node): string
    {
        $type = $node['Node Type'] ?? '';

        if (str_contains($type, 'Index')) {
            return 'index';
        }

        if ($type === 'Seq Scan') {
            return 'seq_scan';
        }

        return 'other';
    }

    /**
     * Recursively collect all node types in the plan tree.
     *
     * @param  array<string, mixed> $node
     * @return list<string>
     */
    private function collectNodeTypes(array $node): array
    {
        $types = [];

        if (isset($node['Node Type'])) {
            $types[] = (string) $node['Node Type'];
        }

        foreach ($node['Plans'] ?? [] as $child) {
            $types = array_merge($types, $this->collectNodeTypes((array) $child));
        }

        return array_values(array_unique($types));
    }

    /**
     * Recursively collect all index names used in the plan tree.
     *
     * @param  array<string, mixed> $node
     * @return list<string>
     */
    private function collectIndexes(array $node): array
    {
        $indexes = [];

        if (isset($node['Index Name'])) {
            $indexes[] = (string) $node['Index Name'];
        }

        foreach ($node['Plans'] ?? [] as $child) {
            $indexes = array_merge($indexes, $this->collectIndexes((array) $child));
        }

        return array_values(array_unique($indexes));
    }
}
