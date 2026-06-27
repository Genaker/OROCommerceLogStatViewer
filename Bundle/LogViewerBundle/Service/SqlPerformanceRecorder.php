<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Flushes SQL issue data to the database via a single bulk PostgreSQL UPSERT.
 *
 * One call to flush() produces at most one executeStatement() call regardless
 * of how many issue rows are passed, keeping DB round-trips to a minimum.
 * All errors are silently swallowed so monitoring never breaks the application.
 *
 * For slow/N+1 issues the AI prompt is generated and stored in analysis_data.aiPrompt.
 * The actual AI call is triggered on-demand via the Ask AI button in the grid UI.
 */
class SqlPerformanceRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PerfDashboardConfig $config,
        private readonly SqlExplainRunner $explainRunner,
        private readonly SqlAiAnalyzer $aiAnalyzer,
    ) {
    }

    /**
     * Persists detected SQL issues via a single multi-row UPSERT.
     *
     * occurrence_count accumulates the total number of executions of this
     * SQL template across all requests (not the number of requests).
     *
     * @param array<int, array{
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
     * }> $issues
     */
    public function flush(array $issues): void
    {
        if (!$this->config->isSqlTrackingEnabled() || empty($issues)) {
            return;
        }

        try {
            $this->explainRunner->resetCounter();
            $enrichedIssues = $this->enrichWithExplain($issues);
            $enrichedIssues = $this->enrichWithAi($enrichedIssues);
            $this->executeBulkUpsert($enrichedIssues);
        } catch (\Throwable) {
            // SQL issue monitoring must never break the application
        }
    }

    /**
     * Run EXPLAIN for slow queries and merge the plan into analysisData.
     *
     * @param  array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithExplain(array $issues): array
    {

        foreach ($issues as &$issue) {
            if (!$issue['isSlow']) {
                continue;
            }

            $plan = $this->explainRunner->explain(
                (string) $issue['template'],
                $issue['params'] ?? null,
            );

            if ($plan !== null && $plan !== []) {
                $issue['analysisData']['explainPlan'] = $plan;

                // Append index / seq-scan info to the suggestion
                $scanType = $plan['scanType'] ?? null;
                if ($scanType === 'seq_scan') {
                    $table    = $plan['filterCond'] ?? '';
                    $issue['suggestion'] = trim($issue['suggestion'] . ' Sequential scan detected'
                        . ($table ? ' (filter: ' . $table . ')' : '')
                        . ' — consider adding an index.');
                } elseif ($scanType === 'index' && !empty($plan['indexesUsed'])) {
                    $issue['suggestion'] = trim($issue['suggestion'] . ' Index used: '
                        . implode(', ', $plan['indexesUsed']) . '.');
                }
            }
        }
        unset($issue);

        return $issues;
    }

    /**
     * Call AI analyzer for issues that have EXPLAIN data or are N+1/slow issues.
     * Stores only the prompt — the actual API call is triggered on-demand via the UI.
     *
     * @param  array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithAi(array $issues): array
    {
        foreach ($issues as &$issue) {
            if (!$issue['isSlow'] && !$issue['isN1']) {
                continue;
            }

            $explainPlan = $issue['analysisData']['explainPlan'] ?? null;
            $issue['analysisData']['aiPrompt'] = $this->aiAnalyzer->generatePrompt($issue, $explainPlan);
        }
        unset($issue);

        return $issues;
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function executeBulkUpsert(array $issues): void
    {
        $valuePlaceholders = [];
        $params            = [];

        foreach ($issues as $index => $issue) {
            $valuePlaceholders[] = sprintf(
                '(:tpl_%d, :n1_%d, :slow_%d, :n1cnt_%d, :slowms_%d, :cnt_%d, NOW(), :caller_%d, :params_%d, :url_%d, :sug_%d, :analysis_%d)',
                $index,
                $index,
                $index,
                $index,
                $index,
                $index,
                $index,
                $index,
                $index,
                $index,
                $index
            );

            $params['tpl_'      . $index] = $issue['template'];
            $params['n1_'       . $index] = $issue['isN1'] ? 'true' : 'false';
            $params['slow_'     . $index] = $issue['isSlow'] ? 'true' : 'false';
            $params['n1cnt_'    . $index] = $issue['worstN1Count'];
            $params['slowms_'   . $index] = $issue['worstSlowMs'];
            $params['cnt_'      . $index] = $issue['executionCount'];
            $params['caller_'   . $index] = $issue['caller'];
            $params['params_'   . $index] = $issue['params'] !== null ? json_encode($issue['params']) : null;
            $params['url_'      . $index] = $issue['url'];
            $params['sug_'      . $index] = ($issue['suggestion'] ?? '') !== '' ? $issue['suggestion'] : null;
            $params['analysis_' . $index] = !empty($issue['analysisData']) ? json_encode($issue['analysisData']) : null;
        }

        $valuesClause = implode(",\n  ", $valuePlaceholders);

        $sql = <<<SQL
INSERT INTO genaker_sql_issue
    (sql_template, is_n1, is_slow, worst_n1_count, worst_slow_ms,
     occurrence_count, last_seen_at, last_caller, last_params, last_url,
     suggestion, analysis_data)
VALUES
  {$valuesClause}
ON CONFLICT (sql_template) DO UPDATE SET
    is_n1            = genaker_sql_issue.is_n1 OR EXCLUDED.is_n1,
    is_slow          = genaker_sql_issue.is_slow OR EXCLUDED.is_slow,
    worst_n1_count   = GREATEST(genaker_sql_issue.worst_n1_count, EXCLUDED.worst_n1_count),
    worst_slow_ms    = GREATEST(genaker_sql_issue.worst_slow_ms, EXCLUDED.worst_slow_ms),
    occurrence_count = genaker_sql_issue.occurrence_count + EXCLUDED.occurrence_count,
    last_seen_at     = EXCLUDED.last_seen_at,
    suggestion       = COALESCE(EXCLUDED.suggestion, genaker_sql_issue.suggestion),
    analysis_data    = COALESCE(EXCLUDED.analysis_data, genaker_sql_issue.analysis_data),
    last_caller = CASE
        WHEN COALESCE(EXCLUDED.worst_slow_ms, 0) > COALESCE(genaker_sql_issue.worst_slow_ms, 0)
          OR COALESCE(EXCLUDED.worst_n1_count, 0) > COALESCE(genaker_sql_issue.worst_n1_count, 0)
        THEN EXCLUDED.last_caller
        ELSE genaker_sql_issue.last_caller
    END,
    last_params = CASE
        WHEN COALESCE(EXCLUDED.worst_slow_ms, 0) > COALESCE(genaker_sql_issue.worst_slow_ms, 0)
          OR COALESCE(EXCLUDED.worst_n1_count, 0) > COALESCE(genaker_sql_issue.worst_n1_count, 0)
        THEN EXCLUDED.last_params
        ELSE genaker_sql_issue.last_params
    END,
    last_url = CASE
        WHEN COALESCE(EXCLUDED.worst_slow_ms, 0) > COALESCE(genaker_sql_issue.worst_slow_ms, 0)
          OR COALESCE(EXCLUDED.worst_n1_count, 0) > COALESCE(genaker_sql_issue.worst_n1_count, 0)
        THEN EXCLUDED.last_url
        ELSE genaker_sql_issue.last_url
    END
SQL;

        $this->connection->executeStatement($sql, $params);
    }
}
