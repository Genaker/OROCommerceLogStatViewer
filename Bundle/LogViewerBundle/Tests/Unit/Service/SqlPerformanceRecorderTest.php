<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Genaker\Bundle\LogViewerBundle\Service\SqlAiAnalyzer;
use Genaker\Bundle\LogViewerBundle\Service\SqlExplainRunner;
use Genaker\Bundle\LogViewerBundle\Service\SqlPerformanceRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\LogViewerBundle\Service\SqlPerformanceRecorder
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class SqlPerformanceRecorderTest extends TestCase
{
    private Connection&MockObject $connection;
    private PerfDashboardConfig&MockObject $config;
    private SqlExplainRunner&MockObject $explainRunner;
    private SqlAiAnalyzer&MockObject $aiAnalyzer;
    private SqlPerformanceRecorder $recorder;

    protected function setUp(): void
    {
        $this->connection    = $this->createMock(Connection::class);
        $this->config        = $this->createMock(PerfDashboardConfig::class);
        $this->explainRunner = $this->createMock(SqlExplainRunner::class);
        $this->aiAnalyzer    = $this->createMock(SqlAiAnalyzer::class);
        $this->recorder      = new SqlPerformanceRecorder(
            $this->connection,
            $this->config,
            $this->explainRunner,
            $this->aiAnalyzer,
        );
    }

    public function testFlushEmptyIssuesDoesNotHitDatabase(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->connection->expects(self::never())->method('executeStatement');

        $this->recorder->flush([]);
    }

    public function testFlushDisabledDoesNotHitDatabase(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(false);
        $this->connection->expects(self::never())->method('executeStatement');

        $this->recorder->flush([$this->makeIssue('SELECT 1')]);
    }

    public function testFlushSingleIssueExecutesOneStatement(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);

        $executedSql    = null;
        $executedParams = null;

        $this->connection->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedSql, &$executedParams): int {
                $executedSql    = $sql;
                $executedParams = $params;
                return 1;
            });

        $this->recorder->flush([$this->makeIssue('SELECT 1')]);

        self::assertStringContainsString('ON CONFLICT', $executedSql);
        self::assertArrayHasKey('tpl_0', $executedParams);
    }

    public function testFlushMultipleIssuesStillExecutesOneStatement(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->connection->expects(self::once())->method('executeStatement')->willReturn(2);

        $this->recorder->flush([
            $this->makeIssue('SELECT 1'),
            $this->makeIssue('SELECT 2'),
        ]);
    }

    public function testFlushParamNamingIsDistinctPerRow(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);

        $executedParams = null;
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedParams): int {
                $executedParams = $params;
                return 2;
            });

        $this->recorder->flush([
            $this->makeIssue('SELECT 1'),
            $this->makeIssue('SELECT 2'),
        ]);

        self::assertArrayHasKey('tpl_0', $executedParams);
        self::assertArrayHasKey('tpl_1', $executedParams);
        self::assertSame('SELECT 1', $executedParams['tpl_0']);
        self::assertSame('SELECT 2', $executedParams['tpl_1']);
    }

    public function testFlushSwallowsDatabaseException(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);
        $this->connection->method('executeStatement')->willThrowException(new \RuntimeException('DB down'));

        // Must not throw
        $this->recorder->flush([$this->makeIssue('SELECT 1')]);

        $this->expectNotToPerformAssertions();
    }

    public function testFlushEncodesParamsAsJson(): void
    {
        $this->config->method('isSqlTrackingEnabled')->willReturn(true);

        $executedParams = null;
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedParams): int {
                $executedParams = $params;
                return 1;
            });

        $issue              = $this->makeIssue('SELECT 1');
        $issue['params']    = ['id' => 42];
        $this->recorder->flush([$issue]);

        self::assertSame('{"id":42}', $executedParams['params_0']);
    }

    /** @return array<string, mixed> */
    private function makeIssue(string $template): array
    {
        return [
            'template'      => $template,
            'isN1'          => true,
            'isSlow'        => false,
            'executionCount' => 1,
            'worstN1Count'  => 6,
            'worstSlowMs'   => null,
            'caller'        => 'App\\Controller::action',
            'params'        => null,
            'url'           => '/admin/test',
            'suggestion'    => null,
            'analysisData'  => [],
        ];
    }
}
