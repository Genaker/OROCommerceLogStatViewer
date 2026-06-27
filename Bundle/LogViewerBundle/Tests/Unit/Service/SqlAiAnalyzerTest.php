<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\SqlAiAnalyzer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\LogViewerBundle\Service\SqlAiAnalyzer
 */
class SqlAiAnalyzerTest extends TestCase
{
    private function makeAnalyzer(string $apiKey = '', string $apiUrl = '', string $model = ''): SqlAiAnalyzer
    {
        /** @var PerfDashboardConfig&MockObject $config */
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('getSqlAiApiKey')->willReturn($apiKey);
        $config->method('getSqlAiApiUrl')->willReturn($apiUrl);
        $config->method('getSqlAiModel')->willReturn($model);
        return new SqlAiAnalyzer($config);
    }

    public function testAnalyseReturnsNullWhenApiKeyEmpty(): void
    {
        $analyzer = $this->makeAnalyzer();

        self::assertNull($analyzer->analyse($this->makeIssue()));
    }

    public function testAnalyseReturnsNullWhenApiKeyEmptyWithExplainPlan(): void
    {
        $analyzer = $this->makeAnalyzer();

        $plan = ['nodeType' => 'Seq Scan', 'scanType' => 'seq_scan', 'totalCost' => 500.0];
        self::assertNull($analyzer->analyse($this->makeIssue(), $plan));
    }

    public function testHasApiKeyReturnsFalseWhenEmpty(): void
    {
        self::assertFalse($this->makeAnalyzer()->hasApiKey());
    }

    public function testHasApiKeyReturnsTrueWhenSet(): void
    {
        self::assertTrue($this->makeAnalyzer('sk-test')->hasApiKey());
    }

    public function testBuildPromptContainsSqlTemplate(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $issue  = $this->makeIssue();
        $prompt = $method->invoke($analyzer, $issue, null);

        self::assertStringContainsString('SELECT * FROM orders WHERE id = ?', $prompt);
    }

    public function testBuildPromptContainsN1Context(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $issue  = $this->makeIssue(['isN1' => true, 'worstN1Count' => 12]);
        $prompt = $method->invoke($analyzer, $issue, null);

        self::assertStringContainsString('N+1', $prompt);
        self::assertStringContainsString('12', $prompt);
    }

    public function testBuildPromptContainsSlowQueryContext(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $issue  = $this->makeIssue(['isSlow' => true, 'worstSlowMs' => 450.5]);
        $prompt = $method->invoke($analyzer, $issue, null);

        self::assertStringContainsString('Slow query', $prompt);
        self::assertStringContainsString('450.5', $prompt);
    }

    public function testBuildPromptContainsExplainPlanData(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $plan  = [
            'nodeType'    => 'Seq Scan',
            'scanType'    => 'seq_scan',
            'totalCost'   => 1200.5,
            'planRows'    => 50000,
            'indexesUsed' => [],
            'filterCond'  => '(status = 1)',
        ];
        $prompt = $method->invoke($analyzer, $this->makeIssue(), $plan);

        self::assertStringContainsString('EXPLAIN', $prompt);
        self::assertStringContainsString('Seq Scan', $prompt);
        self::assertStringContainsString('1200.5', $prompt);
        self::assertStringContainsString('(status = 1)', $prompt);
    }

    public function testBuildPromptContainsIndexUsed(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $plan  = [
            'nodeType'    => 'Index Scan',
            'scanType'    => 'index',
            'indexesUsed' => ['idx_orders_customer_id'],
            'indexCond'   => '(customer_id = 42)',
        ];
        $prompt = $method->invoke($analyzer, $this->makeIssue(), $plan);

        self::assertStringContainsString('idx_orders_customer_id', $prompt);
        self::assertStringContainsString('(customer_id = 42)', $prompt);
    }

    public function testBuildPromptContainsCallerAndExistingSuggestion(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $issue  = $this->makeIssue([
            'caller'     => 'App\\Repository\\OrderRepository::findByCustomer',
            'suggestion' => 'Batch into IN clause',
        ]);
        $prompt = $method->invoke($analyzer, $issue, null);

        self::assertStringContainsString('OrderRepository::findByCustomer', $prompt);
        self::assertStringContainsString('Batch into IN clause', $prompt);
    }

    public function testBuildPromptContainsAnalysisStats(): void
    {
        $analyzer = $this->makeAnalyzer();

        $method = new \ReflectionMethod(SqlAiAnalyzer::class, 'buildPrompt');
        $method->setAccessible(true);

        $issue  = $this->makeIssue([
            'analysisData' => [
                'executions'         => 15,
                'uniqueParamSets'    => 1,
                'allParamsIdentical' => true,
                'avgMs'              => 8.2,
                'minMs'              => 6.1,
                'maxMs'              => 12.3,
                'totalMs'            => 123.0,
            ],
        ]);
        $prompt = $method->invoke($analyzer, $issue, null);

        self::assertStringContainsString('15', $prompt);
        self::assertStringContainsString('identical parameters', $prompt);
        self::assertStringContainsString('123', $prompt);
    }

    public function testGeneratePromptDelegatesToBuildPrompt(): void
    {
        $analyzer = $this->makeAnalyzer();
        $issue    = $this->makeIssue();
        $prompt   = $analyzer->generatePrompt($issue, null);

        self::assertStringContainsString('SELECT * FROM orders WHERE id = ?', $prompt);
    }

    public function testAnalyseFromPromptReturnsNullWhenNoApiKey(): void
    {
        $analyzer = $this->makeAnalyzer();
        self::assertNull($analyzer->analyseFromPrompt('some prompt text'));
    }

    /** @param array<string, mixed> $overrides */
    private function makeIssue(array $overrides = []): array
    {
        return array_merge([
            'template'      => 'SELECT * FROM orders WHERE id = ?',
            'isN1'          => false,
            'isSlow'        => false,
            'executionCount' => 1,
            'worstN1Count'  => null,
            'worstSlowMs'   => null,
            'caller'        => 'App\\Controller\\OrderController::index',
            'params'        => null,
            'url'           => '/admin/orders',
            'suggestion'    => null,
            'analysisData'  => [],
        ], $overrides);
    }
}
