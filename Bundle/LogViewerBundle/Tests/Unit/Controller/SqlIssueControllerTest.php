<?php

declare(strict_types=1);

// phpcs:ignoreFile
// @SuppressWarnings(PHPMD.TooManyPublicMethods)

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\LogViewerBundle\Controller\SqlIssueController;
use Genaker\Bundle\LogViewerBundle\Service\SqlAiAnalyzer;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @covers \Genaker\Bundle\LogViewerBundle\Controller\SqlIssueController
 */
class SqlIssueControllerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject             $connection;
    private SqlAiAnalyzer&MockObject          $aiAnalyzer;
    private SqlIssueController               $controller;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection    = $this->createMock(Connection::class);
        $this->aiAnalyzer    = $this->createMock(SqlAiAnalyzer::class);

        $this->controller = new SqlIssueController(
            $this->entityManager,
            $this->connection,
            $this->aiAnalyzer,
        );
    }

    // -----------------------------------------------------------------------
    // Structural / wiring tests
    // -----------------------------------------------------------------------

    public function testControllerExtendsAbstractController(): void
    {
        self::assertInstanceOf(AbstractController::class, $this->controller);
    }

    public function testIndexActionRouteAttribute(): void
    {
        $ref        = new \ReflectionMethod(SqlIssueController::class, 'indexAction');
        $attributes = $ref->getAttributes(Route::class);

        self::assertNotEmpty($attributes, 'indexAction must have a #[Route] attribute');

        $route = $attributes[0]->newInstance();
        self::assertSame('/admin/sql-issues', $route->getPath());
        self::assertSame('genaker_sql_issue_index', $route->getName());
        self::assertSame(['GET'], $route->getMethods());
    }

    public function testIndexActionHasAclAncestor(): void
    {
        $ref        = new \ReflectionMethod(SqlIssueController::class, 'indexAction');
        $attributes = $ref->getAttributes(AclAncestor::class);

        self::assertNotEmpty($attributes, 'indexAction must have #[AclAncestor]');
        self::assertSame('genaker_sql_issue_index', $attributes[0]->newInstance()->getId());
    }

    public function testClearAllActionRouteAttribute(): void
    {
        $ref        = new \ReflectionMethod(SqlIssueController::class, 'clearAllAction');
        $attributes = $ref->getAttributes(Route::class);

        self::assertNotEmpty($attributes, 'clearAllAction must have a #[Route] attribute');

        $route = $attributes[0]->newInstance();
        self::assertSame('/admin/sql-issues/clear-all', $route->getPath());
        self::assertSame('genaker_sql_issue_clear_all', $route->getName());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testAskAiActionRouteAttribute(): void
    {
        $ref        = new \ReflectionMethod(SqlIssueController::class, 'askAiAction');
        $attributes = $ref->getAttributes(Route::class);

        self::assertNotEmpty($attributes, 'askAiAction must have a #[Route] attribute');

        $route = $attributes[0]->newInstance();
        self::assertSame('/admin/sql-issues/{id}/ask-ai', $route->getPath());
        self::assertSame('genaker_sql_issue_ask_ai', $route->getName());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testAskAiActionHasAclAncestor(): void
    {
        $ref        = new \ReflectionMethod(SqlIssueController::class, 'askAiAction');
        $attributes = $ref->getAttributes(AclAncestor::class);

        self::assertNotEmpty($attributes, 'askAiAction must have #[AclAncestor]');
        self::assertSame('genaker_sql_issue_index', $attributes[0]->newInstance()->getId());
    }

    // -----------------------------------------------------------------------
    // askAiAction — business logic tests
    // -----------------------------------------------------------------------

    public function testAskAiActionReturns400WhenNoApiKey(): void
    {
        $this->aiAnalyzer->method('hasApiKey')->willReturn(false);

        $response = $this->controller->askAiAction(1);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsString('API key', $body['error']);
    }

    public function testAskAiActionReturns404WhenRowNotFound(): void
    {
        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->connection
            ->method('fetchAssociative')
            ->willReturn(false);

        $response = $this->controller->askAiAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Issue not found', $body['error']);
    }

    public function testAskAiActionReturns400WhenAnalysisDataHasNoPrompt(): void
    {
        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->connection
            ->method('fetchAssociative')
            ->willReturn(['analysis_data' => json_encode(['executions' => 3])]);

        $response = $this->controller->askAiAction(5);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('prompt', $body['error']);
    }

    public function testAskAiActionReturns400WhenAnalysisDataIsNull(): void
    {
        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->connection
            ->method('fetchAssociative')
            ->willReturn(['analysis_data' => null]);

        $response = $this->controller->askAiAction(7);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAskAiActionReturns500WhenAiReturnsNull(): void
    {
        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->aiAnalyzer->method('analyseFromPrompt')->willReturn(null);

        $this->connection
            ->method('fetchAssociative')
            ->willReturn(['analysis_data' => json_encode(['aiPrompt' => 'analyse this SQL'])]);

        $response = $this->controller->askAiAction(10);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $body);
    }

    public function testAskAiActionReturns200WithAnalysisOnSuccess(): void
    {
        $expectedAnalysis = 'Add an index on orders.customer_id to eliminate the Seq Scan.';

        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->aiAnalyzer->method('analyseFromPrompt')->willReturn($expectedAnalysis);

        $this->connection
            ->method('fetchAssociative')
            ->willReturn(['analysis_data' => json_encode(['aiPrompt' => 'analyse this SQL'])]);

        $this->connection->expects(self::once())
            ->method('executeStatement');

        $response = $this->controller->askAiAction(42);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame($expectedAnalysis, $body['analysis']);
    }

    public function testAskAiActionSavesAnalysisBackToDatabase(): void
    {
        $aiText       = 'Use a covering index on (status, created_at).';
        $originalData = ['aiPrompt' => 'context here', 'executions' => 5];

        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->aiAnalyzer->method('analyseFromPrompt')->willReturn($aiText);

        $this->connection
            ->method('fetchAssociative')
            ->willReturn(['analysis_data' => json_encode($originalData)]);

        $savedJson = null;
        $this->connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE genaker_sql_issue'),
                self::callback(function (array $params) use (&$savedJson, $aiText): bool {
                    $savedJson = json_decode($params['data'], true);
                    return $params['id'] === 42 && isset($savedJson['aiAnalysis']);
                })
            );

        $this->controller->askAiAction(42);

        self::assertNotNull($savedJson);
        self::assertSame($aiText, $savedJson['aiAnalysis']);
        // Original fields must be preserved
        self::assertSame(5, $savedJson['executions']);
        self::assertSame('context here', $savedJson['aiPrompt']);
    }

    public function testAskAiActionPassesPromptToAiAnalyzer(): void
    {
        $storedPrompt = 'SELECT * FROM orders — N+1 x7 — analyse this';

        $this->aiAnalyzer->method('hasApiKey')->willReturn(true);
        $this->aiAnalyzer
            ->expects(self::once())
            ->method('analyseFromPrompt')
            ->with($storedPrompt)
            ->willReturn('Some recommendation');

        $this->connection
            ->method('fetchAssociative')
            ->willReturn(['analysis_data' => json_encode(['aiPrompt' => $storedPrompt])]);

        $this->connection->method('executeStatement');

        $this->controller->askAiAction(3);
    }

    public function testAskAiActionJsonResponseHeaderIsApplicationJson(): void
    {
        $this->aiAnalyzer->method('hasApiKey')->willReturn(false);

        $response = $this->controller->askAiAction(1);

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }
}
