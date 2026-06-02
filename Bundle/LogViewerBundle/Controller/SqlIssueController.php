<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\LogViewerBundle\Service\SqlAiAnalyzer;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Provides admin UI for viewing and clearing tracked SQL issues.
 */
class SqlIssueController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly SqlAiAnalyzer $aiAnalyzer,
    ) {
    }

    #[Route(path: '/admin/sql-issues', name: 'genaker_sql_issue_index', methods: ['GET'])]
    #[AclAncestor('genaker_sql_issue_index')]
    public function indexAction(): Response
    {
        return $this->render('@GenakerLogViewer/SqlIssue/index.html.twig');
    }

    #[Route(path: '/admin/sql-issues/clear-all', name: 'genaker_sql_issue_clear_all', methods: ['POST'])]
    #[AclAncestor('genaker_sql_issue_index')]
    public function clearAllAction(): Response
    {
        try {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM genaker_sql_issue');
        } catch (\Throwable) {
            // Best-effort clear
        }

        return $this->redirectToRoute('genaker_sql_issue_index');
    }

    #[Route(
        path: '/admin/sql-issues/{id}/ask-ai',
        name: 'genaker_sql_issue_ask_ai',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
        options: ['expose' => true, 'csrf_protection' => false]
    )]
    #[AclAncestor('genaker_sql_issue_index')]
    public function askAiAction(int $id): JsonResponse
    {
        if (!$this->aiAnalyzer->hasApiKey()) {
            return new JsonResponse(
                ['error' => 'No API key configured. Set it in System > Configuration > Log Viewer.'],
                400
            );
        }

        $row = $this->connection->fetchAssociative(
            'SELECT analysis_data FROM genaker_sql_issue WHERE id = :id',
            ['id' => $id]
        );

        if (!$row) {
            return new JsonResponse(['error' => 'Issue not found'], 404);
        }

        $analysisData = json_decode($row['analysis_data'] ?? '{}', true) ?? [];
        $prompt = $analysisData['aiPrompt'] ?? '';

        if ($prompt === '') {
            return new JsonResponse(['error' => 'No prompt stored for this issue'], 400);
        }

        $analysis = $this->aiAnalyzer->analyseFromPrompt($prompt);
        if ($analysis === null) {
            return new JsonResponse(['error' => 'AI request failed or returned no result'], 500);
        }

        $analysisData['aiAnalysis'] = $analysis;
        $this->connection->executeStatement(
            'UPDATE genaker_sql_issue SET analysis_data = :data WHERE id = :id',
            ['data' => json_encode($analysisData), 'id' => $id]
        );

        return new JsonResponse(['analysis' => $analysis]);
    }
}
