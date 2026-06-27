<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Sends SQL performance context to an OpenAI-compatible chat API and returns
 * a short, actionable recommendation.
 *
 * API key and endpoint are read at call-time from PerfDashboardConfig so they
 * can be changed via System > Configuration without a cache clear.
 *
 * The service is a no-op when no API key is configured.
 */
class SqlAiAnalyzer
{
    private const string DEFAULT_API_URL = 'https://api.openai.com/v1/chat/completions';
    private const string DEFAULT_MODEL   = 'gpt-4o-mini';
    private const int    MAX_TOKENS      = 350;
    private const float  TEMPERATURE     = 0.2;
    private const int    TIMEOUT_SECONDS = 10;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are an expert PostgreSQL database performance engineer and PHP/Symfony developer.
Analyse the provided SQL issue context and give 2-3 concise, specific, actionable
recommendations. Focus on the root cause and what code or schema change will fix it.
Keep the response under 180 words. Use plain text — no markdown, no bullet symbols,
no headers. Separate recommendations with a blank line.
PROMPT;

    public function __construct(
        private readonly PerfDashboardConfig $config,
    ) {
    }

    public function hasApiKey(): bool
    {
        return $this->config->getSqlAiApiKey() !== '';
    }

    /**
     * Analyse a single SQL issue and return an AI recommendation.
     *
     * @param array<string, mixed>      $issue
     * @param array<string, mixed>|null $explainPlan
     */
    public function analyse(array $issue, ?array $explainPlan = null): ?string
    {
        return $this->analyseFromPrompt($this->buildPrompt($issue, $explainPlan));
    }

    /**
     * Send a pre-built prompt string to the AI API.
     * Used by the on-demand Ask AI controller action.
     */
    public function analyseFromPrompt(string $prompt): ?string
    {
        $apiKey = $this->config->getSqlAiApiKey();
        if ($apiKey === '') {
            return null;
        }

        $apiUrl = $this->config->getSqlAiApiUrl();
        $model  = $this->config->getSqlAiModel();
        $apiUrl = $apiUrl !== '' ? $apiUrl : self::DEFAULT_API_URL;
        $model  = $model  !== '' ? $model  : self::DEFAULT_MODEL;

        try {
            $client   = HttpClient::create(['timeout' => self::TIMEOUT_SECONDS]);
            $response = $client->request('POST', $apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => $model,
                    'max_tokens'  => self::MAX_TOKENS,
                    'temperature' => self::TEMPERATURE,
                    'messages'    => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user',   'content' => $prompt],
                    ],
                ],
            ]);

            $data = $response->toArray(false);
            $text = $data['choices'][0]['message']['content'] ?? null;

            return is_string($text) ? trim($text) : null;
        } catch (TransportExceptionInterface) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build and return the prompt that would be sent to the AI API.
     * Public so callers can store it for manual copy-paste when no API key is set.
     *
     * @param array<string, mixed>      $issue
     * @param array<string, mixed>|null $explainPlan
     */
    public function generatePrompt(array $issue, ?array $explainPlan = null): string
    {
        return $this->buildPrompt($issue, $explainPlan);
    }

    /**
     * Build the user prompt from the issue context, analysis stats, and EXPLAIN plan.
     *
     * @param array<string, mixed>      $issue
     * @param array<string, mixed>|null $explainPlan
     */
    private function buildPrompt(array $issue, ?array $explainPlan): string
    {
        $lines = array_merge(
            $this->buildSqlSection($issue),
            $this->buildFlagsSection($issue),
            $this->buildStatsSection($issue['analysisData'] ?? []),
            $this->buildExplainSection($explainPlan ?? []),
            $this->buildCallerSection($issue),
            ['Please provide specific recommendations to fix this SQL performance issue.'],
        );

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $issue @return string[] */
    private function buildSqlSection(array $issue): array
    {
        return ['SQL TEMPLATE (parameters replaced with ?):', $issue['template'] ?? '(unknown)', ''];
    }

    /** @param array<string, mixed> $issue @return string[] */
    private function buildFlagsSection(array $issue): array
    {
        $flags = [];
        if (!empty($issue['isN1'])) {
            $flags[] = 'N+1 detected (same query repeated ' . ($issue['worstN1Count'] ?? '?') . 'x per request)';
        }
        if (!empty($issue['isSlow'])) {
            $flags[] = 'Slow query (' . ($issue['worstSlowMs'] ?? '?') . ' ms total)';
        }

        return empty($flags) ? [] : ['ISSUES: ' . implode('; ', $flags), ''];
    }

    /** @param array<string, mixed> $analysisData @return string[] */
    private function buildStatsSection(array $analysisData): array
    {
        if (empty($analysisData)) {
            return [];
        }

        $lines   = ['EXECUTION STATS:'];
        $mapping = [
            'executions'      => '- Executions in request: ',
            'uniqueParamSets' => '- Unique parameter sets: ',
            'totalMs'         => '- Total ms spent on this template: ',
        ];
        foreach ($mapping as $key => $label) {
            if (isset($analysisData[$key])) {
                $lines[] = $label . $analysisData[$key];
            }
        }
        if (!empty($analysisData['allParamsIdentical'])) {
            $lines[] = '- All executions used identical parameters (cache opportunity)';
        }
        $hasTimingStats = isset($analysisData['avgMs'], $analysisData['minMs'], $analysisData['maxMs']);
        if ($hasTimingStats) {
            $lines[] = '- Avg / Min / Max ms: '
                . $analysisData['avgMs'] . ' / '
                . $analysisData['minMs'] . ' / '
                . $analysisData['maxMs'];
        }
        $lines[] = '';

        return $lines;
    }

    /** @param array<string, mixed> $explainPlan @return string[] */
    private function buildExplainSection(array $explainPlan): array
    {
        if (empty($explainPlan)) {
            return [];
        }

        $lines = ['POSTGRESQL EXPLAIN PLAN SUMMARY:'];
        $scalar = [
            'nodeType'  => '- Top node type: ',
            'scanType'  => '- Scan type: ',
            'totalCost' => '- Planner estimated cost: ',
            'planRows'  => '- Estimated rows: ',
            'indexCond' => '- Index condition: ',
            'filterCond' => '- Filter: ',
        ];
        foreach ($scalar as $key => $label) {
            if (!empty($explainPlan[$key])) {
                $lines[] = $label . $explainPlan[$key];
            }
        }
        if (!empty($explainPlan['indexesUsed'])) {
            $lines[] = '- Indexes used: ' . implode(', ', $explainPlan['indexesUsed']);
        }
        if (!empty($explainPlan['allNodeTypes'])) {
            $lines[] = '- All plan nodes: ' . implode(' -> ', $explainPlan['allNodeTypes']);
        }
        $lines[] = '';

        return $lines;
    }

    /** @param array<string, mixed> $issue @return string[] */
    private function buildCallerSection(array $issue): array
    {
        $lines = [];
        if (!empty($issue['caller'])) {
            $lines[] = 'CALLER: ' . $issue['caller'];
            $lines[] = '';
        }
        if (!empty($issue['suggestion'])) {
            $lines[] = 'RULE-BASED SUGGESTION (for context):';
            $lines[] = $issue['suggestion'];
            $lines[] = '';
        }

        return $lines;
    }
}
