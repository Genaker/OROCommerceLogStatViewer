<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Controller;

use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HttpPerformanceController extends AbstractController
{
    #[Route(path: '/admin/http-performance', name: 'genaker_http_performance_index', methods: ['GET'])]
    #[AclAncestor('genaker_http_performance_index')]
    public function indexAction(): Response
    {
        return $this->render('@GenakerLogViewer/HttpPerformance/index.html.twig');
    }
}
