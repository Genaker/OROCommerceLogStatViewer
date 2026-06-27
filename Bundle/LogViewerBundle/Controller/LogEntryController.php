<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Controller;

use Doctrine\DBAL\Connection;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class LogEntryController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route(path: '/admin/log-entries', name: 'genaker_log_entry_index', methods: ['GET'])]
    #[AclAncestor('genaker_log_entry_index')]
    public function indexAction(): Response
    {
        return $this->render('@GenakerLogViewer/LogEntry/index.html.twig');
    }

    #[Route(path: '/admin/log-entries/clear-all', name: 'genaker_log_entry_clear_all', methods: ['POST'])]
    #[AclAncestor('genaker_log_entry_index')]
    public function clearAllAction(): Response
    {
        try {
            $this->connection->executeStatement('DELETE FROM genaker_log_entry');
        } catch (\Throwable) {
            // best-effort
        }

        return $this->redirectToRoute('genaker_log_entry_index');
    }

    #[Route(
        path: '/admin/log-entries/tail',
        name: 'genaker_log_entry_tail',
        methods: ['GET'],
        options: ['expose' => true]
    )]
    #[AclAncestor('genaker_log_entry_index')]
    public function tailAction(Request $request): JsonResponse
    {
        $afterId = (int) $request->query->get('after_id', 0);
        $limit   = max(1, min(500, (int) $request->query->get('limit', 50)));
        $level   = $request->query->get('level', '');
        $channel = $request->query->get('channel', '');
        $search  = trim((string) $request->query->get('search', ''));

        $qb = $this->connection->createQueryBuilder()
            ->select('id', 'channel', 'level', 'level_name', 'message', 'context', 'extra', 'created_at', 'url', 'ip', 'occurrence_count', 'first_seen_at')
            ->from('genaker_log_entry')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        if ($afterId > 0) {
            $qb->andWhere('id > :afterId')->setParameter('afterId', $afterId);
            $qb->orderBy('id', 'ASC');
        }

        if ($level !== '') {
            $qb->andWhere('level_name = :level')->setParameter('level', strtoupper($level));
        }

        if ($channel !== '') {
            $qb->andWhere('channel = :channel')->setParameter('channel', $channel);
        }

        if ($search !== '') {
            $qb->andWhere('message LIKE :search')->setParameter('search', '%' . $search . '%');
        }

        try {
            $rows = $qb->execute()->fetchAllAssociative();
        } catch (\Throwable) {
            $rows = [];
        }

        if ($afterId > 0) {
            $rows = array_reverse($rows);
        }

        foreach ($rows as &$row) {
            $row['context'] = $row['context'] !== null ? json_decode($row['context'], true) : null;
            $row['extra']   = $row['extra'] !== null ? json_decode($row['extra'], true) : null;
        }

        return new JsonResponse(['rows' => $rows]);
    }

    #[Route(
        path: '/admin/log-entries/channels',
        name: 'genaker_log_entry_channels',
        methods: ['GET'],
        options: ['expose' => true]
    )]
    #[AclAncestor('genaker_log_entry_index')]
    public function channelsAction(): JsonResponse
    {
        try {
            $qb = $this->connection->createQueryBuilder()
                ->select('DISTINCT channel')
                ->from('genaker_log_entry')
                ->orderBy('channel', 'ASC');

            $channels = $qb->execute()->fetchFirstColumn();
        } catch (\Throwable) {
            $channels = [];
        }

        return new JsonResponse(['channels' => $channels]);
    }

    #[Route(
        path: '/admin/log-entries/stats',
        name: 'genaker_log_entry_stats',
        methods: ['GET'],
        options: ['expose' => true]
    )]
    #[AclAncestor('genaker_log_entry_index')]
    public function statsAction(): JsonResponse
    {
        try {
            $totalRows = (int) $this->connection->executeQuery(
                'SELECT COUNT(*) FROM genaker_log_entry'
            )->fetchOne();

            $tableSize = (int) $this->connection->executeQuery(
                "SELECT pg_total_relation_size('genaker_log_entry')"
            )->fetchOne();

            $groupedRows = (int) $this->connection->executeQuery(
                'SELECT COUNT(*) FROM genaker_log_entry WHERE message_key IS NOT NULL'
            )->fetchOne();

            $totalOccurrences = (int) $this->connection->executeQuery(
                'SELECT COALESCE(SUM(occurrence_count), 0) FROM genaker_log_entry'
            )->fetchOne();
        } catch (\Throwable) {
            $totalRows = 0;
            $tableSize = 0;
            $groupedRows = 0;
            $totalOccurrences = 0;
        }

        return new JsonResponse([
            'total_rows'        => $totalRows,
            'table_size_bytes'  => $tableSize,
            'grouped_rows'      => $groupedRows,
            'total_occurrences' => $totalOccurrences,
        ]);
    }
}
