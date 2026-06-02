<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\SqlQueryCollector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\LogViewerBundle\Service\SqlQueryCollector
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class SqlQueryCollectorTest extends TestCase
{
    private SqlQueryCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SqlQueryCollector();
    }

    public function testStopQueryAppendsSampleWithCaller(): void
    {
        $this->collector->startQuery('SELECT 1');
        $this->collector->stopQuery();

        self::assertCount(1, $this->collector->queries);
        self::assertArrayHasKey('executionMS', $this->collector->queries[1]);
        self::assertArrayHasKey('caller', $this->collector->queries[1]);
    }

    public function testMultipleQueriesAccumulate(): void
    {
        $this->collector->startQuery('SELECT 1');
        $this->collector->stopQuery();
        $this->collector->startQuery('SELECT 2');
        $this->collector->stopQuery();

        self::assertCount(2, $this->collector->queries);
    }

    public function testGetIssuesClearsBuffer(): void
    {
        $this->collector->startQuery('SELECT 1');
        $this->collector->stopQuery();

        $this->collector->getIssues('/test', 5, 10.0);

        self::assertEmpty($this->collector->queries);
        self::assertSame(0, $this->collector->currentQuery);
    }

    public function testGetIssuesReturnsEmptyWhenNothingTriggered(): void
    {
        // One query well below both thresholds
        $this->collector->startQuery('SELECT 1');
        $this->collector->stopQuery();

        $issues = $this->collector->getIssues('/test', 5, 10000.0);

        self::assertEmpty($issues);
    }

    public function testN1DetectionWhenRepeatCountExceedsThreshold(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = 1');
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);

        self::assertCount(1, $issues);
        self::assertTrue($issues[0]['isN1']);
        self::assertFalse($issues[0]['isSlow']);
        self::assertSame(6, $issues[0]['worstN1Count']);
        self::assertSame(6, $issues[0]['executionCount']);
    }

    public function testN1NotTriggeredWhenAtThreshold(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = 1');
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);

        self::assertEmpty($issues);
    }

    public function testSlowQueryDetectionAboveThreshold(): void
    {
        // Inject a query with artificially high executionMS
        $this->collector->startQuery('SELECT * FROM big_table');
        $this->collector->stopQuery();
        // Manually set execution time to simulate slow query (50ms = 0.05s in DebugStack)
        $this->collector->queries[$this->collector->currentQuery]['executionMS'] = 0.05;

        $issues = $this->collector->getIssues('/test', 5, 10.0);

        self::assertCount(1, $issues);
        self::assertFalse($issues[0]['isN1']);
        self::assertTrue($issues[0]['isSlow']);
        self::assertEqualsWithDelta(50.0, $issues[0]['worstSlowMs'], 0.01);
        self::assertSame(1, $issues[0]['executionCount']);
    }

    public function testBothFlagsSetWhenTemplateTriggersN1AndSlow(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM big_table WHERE id = ?');
            $this->collector->stopQuery();
        }
        // Make the last one slow
        $this->collector->queries[$this->collector->currentQuery]['executionMS'] = 0.1;

        $issues = $this->collector->getIssues('/test', 5, 10.0);

        self::assertCount(1, $issues);
        self::assertTrue($issues[0]['isN1']);
        self::assertTrue($issues[0]['isSlow']);
        // worstSlowMs must be total (sum) across all repeats, not single-worst
        self::assertGreaterThanOrEqual(100.0, $issues[0]['worstSlowMs']);
        self::assertSame(6, $issues[0]['executionCount']);
    }

    public function testN1SlowIsReportedAsTotalMs(): void
    {
        // 3 executions: 20ms, 30ms, 50ms — total = 100ms, max = 50ms
        $this->collector->startQuery('SELECT * FROM t WHERE id = ?');
        $this->collector->stopQuery();
        $this->collector->queries[$this->collector->currentQuery]['executionMS'] = 0.020;

        $this->collector->startQuery('SELECT * FROM t WHERE id = ?');
        $this->collector->stopQuery();
        $this->collector->queries[$this->collector->currentQuery]['executionMS'] = 0.030;

        $this->collector->startQuery('SELECT * FROM t WHERE id = ?');
        $this->collector->stopQuery();
        $this->collector->queries[$this->collector->currentQuery]['executionMS'] = 0.050;

        // threshold=2 so count=3 triggers N+1; slowMs=10 so 50ms triggers slow
        $issues = $this->collector->getIssues('/test', 2, 10.0);

        self::assertCount(1, $issues);
        self::assertTrue($issues[0]['isN1']);
        self::assertTrue($issues[0]['isSlow']);
        // Must report total (100ms), not single-worst (50ms)
        self::assertEqualsWithDelta(100.0, $issues[0]['worstSlowMs'], 0.1);
        self::assertSame(3, $issues[0]['executionCount']);
    }

    public function testUrlIsPassedThroughToIssue(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT 1');
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/admin/orders?page=2', 5, 10000.0);

        self::assertSame('/admin/orders?page=2', $issues[0]['url']);
    }

    public function testNormalizeSqlStripsStringLiterals(): void
    {
        $this->collector->startQuery("SELECT * FROM t WHERE name = 'John'");
        $this->collector->stopQuery();
        $this->collector->startQuery("SELECT * FROM t WHERE name = 'Jane'");
        $this->collector->stopQuery();

        // Both should map to same template
        $issues = $this->collector->getIssues('/test', 1, 10000.0);
        self::assertCount(1, $issues);
        self::assertStringContainsString('?', $issues[0]['template']);
        self::assertStringNotContainsString('John', $issues[0]['template']);
    }

    public function testNormalizeSqlCollapsesInClause(): void
    {
        $this->collector->startQuery('SELECT * FROM t WHERE id IN (1, 2, 3)');
        $this->collector->stopQuery();
        $this->collector->startQuery('SELECT * FROM t WHERE id IN (4, 5, 6, 7)');
        $this->collector->stopQuery();

        $issues = $this->collector->getIssues('/test', 1, 10000.0);
        self::assertCount(1, $issues);
        self::assertStringContainsString('IN (?)', $issues[0]['template']);
    }

    // --- maskSensitiveParams ---

    public function testMaskSensitiveParamsReturnsNullForNull(): void
    {
        self::assertNull($this->collector->maskSensitiveParams(null));
    }

    public function testMaskSensitiveParamsReturnsEmptyForEmpty(): void
    {
        self::assertSame([], $this->collector->maskSensitiveParams([]));
    }

    public function testMaskSensitiveParamsMasksPasswordKey(): void
    {
        $result = $this->collector->maskSensitiveParams(['password' => 'secret123', 'name' => 'John']);
        self::assertSame('***', $result['password']);
        self::assertSame('John', $result['name']);
    }

    public function testMaskSensitiveParamsMasksSecretKey(): void
    {
        $result = $this->collector->maskSensitiveParams(['client_secret' => 'abc', 'id' => 42]);
        self::assertSame('***', $result['client_secret']);
        self::assertSame(42, $result['id']);
    }

    public function testMaskSensitiveParamsMasksTokenKey(): void
    {
        $result = $this->collector->maskSensitiveParams(['access_token' => 'tok_xyz', 'user' => 'admin']);
        self::assertSame('***', $result['access_token']);
        self::assertSame('admin', $result['user']);
    }

    public function testMaskSensitiveParamsMasksAuthKey(): void
    {
        $result = $this->collector->maskSensitiveParams(['authorization' => 'Bearer abc', 'page' => 1]);
        self::assertSame('***', $result['authorization']);
        self::assertSame(1, $result['page']);
    }

    public function testMaskSensitiveParamsMasksApiKeyVariant(): void
    {
        $result = $this->collector->maskSensitiveParams(['api_key' => 'key-123', 'apikey' => 'key-456']);
        self::assertSame('***', $result['api_key']);
        self::assertSame('***', $result['apikey']);
    }

    public function testMaskSensitiveParamsMasksPrivateAndAccessKeys(): void
    {
        $result = $this->collector->maskSensitiveParams(['private_key' => 'pem', 'access_key' => 'AKIA']);
        self::assertSame('***', $result['private_key']);
        self::assertSame('***', $result['access_key']);
    }

    public function testMaskSensitiveParamsMasksCredentialKey(): void
    {
        $result = $this->collector->maskSensitiveParams(['credentials' => 'user:pass']);
        self::assertSame('***', $result['credentials']);
    }

    public function testMaskSensitiveParamsMasksPasswdVariant(): void
    {
        $result = $this->collector->maskSensitiveParams(['db_passwd' => 'hunter2']);
        self::assertSame('***', $result['db_passwd']);
    }

    public function testMaskSensitiveParamsIsCaseInsensitive(): void
    {
        $result = $this->collector->maskSensitiveParams(['PASSWORD' => 'top', 'User_Secret' => 'x']);
        self::assertSame('***', $result['PASSWORD']);
        self::assertSame('***', $result['User_Secret']);
    }

    public function testMaskSensitiveParamsLeavesPositionalArrayUnchanged(): void
    {
        $params = ['value1', 'value2', 42];
        self::assertSame($params, $this->collector->maskSensitiveParams($params));
    }

    public function testMaskSensitiveParamsMasksNestedAssociativeKeys(): void
    {
        $params = ['user' => ['password' => 'hidden', 'name' => 'Alice']];
        $result = $this->collector->maskSensitiveParams($params);
        self::assertSame('***', $result['user']['password']);
        self::assertSame('Alice', $result['user']['name']);
    }

    public function testMaskSensitiveParamsPreservesNonSensitiveAssocKeys(): void
    {
        $params = ['order_id' => 123, 'sku' => 'ABC-001', 'qty' => 5.0];
        self::assertSame($params, $this->collector->maskSensitiveParams($params));
    }

    // --- suggestion and analysisData ---

    public function testIssueContainsSuggestionAndAnalysisDataFields(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = 1');
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);

        self::assertArrayHasKey('suggestion', $issues[0]);
        self::assertArrayHasKey('analysisData', $issues[0]);
    }

    public function testSuggestionRecommendsCacheForIdenticalParams(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = ?', [42]);
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);

        self::assertNotEmpty($issues[0]['suggestion']);
        self::assertStringContainsStringIgnoringCase('identical', $issues[0]['suggestion']);
        self::assertStringContainsStringIgnoringCase('cache', $issues[0]['suggestion']);
    }

    public function testSuggestionRecommendsBatchForDifferentParams(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = ?', [$i]);
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);

        self::assertStringContainsStringIgnoringCase('batch', $issues[0]['suggestion']);
    }

    public function testAnalysisDataContainsExpectedKeys(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = ?', [$i]);
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);
        $data   = $issues[0]['analysisData'];

        self::assertArrayHasKey('executions', $data);
        self::assertArrayHasKey('uniqueParamSets', $data);
        self::assertArrayHasKey('sameParamsRepeats', $data);
        self::assertArrayHasKey('allParamsIdentical', $data);
        self::assertArrayHasKey('avgMs', $data);
        self::assertArrayHasKey('minMs', $data);
        self::assertArrayHasKey('maxMs', $data);
        self::assertArrayHasKey('totalMs', $data);
        self::assertSame(6, $data['executions']);
        self::assertSame(6, $data['uniqueParamSets']);
        self::assertFalse($data['allParamsIdentical']);
    }

    public function testAnalysisDataIdenticalParamsDetected(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->collector->startQuery('SELECT * FROM orders WHERE id = ?', [99]);
            $this->collector->stopQuery();
        }

        $issues = $this->collector->getIssues('/test', 5, 10000.0);
        $data   = $issues[0]['analysisData'];

        self::assertSame(1, $data['uniqueParamSets']);
        self::assertSame(6, $data['sameParamsRepeats']);
        self::assertTrue($data['allParamsIdentical']);
    }
}

