<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Controller;

use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin controller for the server performance dashboard.
 *
 * Exposes three routes:
 *  - index:     renders the dashboard page
 *  - report:    (POST) collects this instance's metrics and stores them
 *  - instances: (GET)  returns all live instances' snapshots as JSON
 */
#[Route('/admin/perf', name: 'genaker_perf_dashboard_')]
class PerfDashboardController extends AbstractController
{
    public function __construct(
        private readonly PerfDashboardStore $store
    ) {
    }

    /**
     * Renders the performance dashboard page.
     */
    #[Route('', name: 'index')]
    #[Acl(
        id: 'genaker_perf_dashboard_index',
        type: 'action',
        label: 'View Performance Dashboard',
        group_name: '',
        category: ''
    )]
    public function index(): Response
    {
        return $this->render('@GenakerLogViewer/PerfDashboard/index.html.twig');
    }

    /**
     * Returns all live instances' metric snapshots as JSON.
     */
    #[Route('/instances', name: 'instances', methods: ['GET'])]
    #[AclAncestor('genaker_perf_dashboard_index')]
    public function instances(): JsonResponse
    {
        return new JsonResponse($this->store->loadAll());
    }
}
