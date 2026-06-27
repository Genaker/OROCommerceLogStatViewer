<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Controller;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin "Delivery Route Planner": shows today's ready-to-ship orders grouped
 * into one route per driver, and lets the dispatcher download a CSV routing
 * sheet per driver.
 *
 * The page itself is thin — it renders defaults from system configuration and
 * the JS calls POST /comivoyager/vrp/optimize-orders to do the work.
 */
class RoutePlannerController extends AbstractController
{
    public function __construct(
        private readonly ConfigManager $configManager,
    ) {
    }

    #[Route(
        path: '/admin/comivoyager/planner',
        name: 'genaker_comivoyager_planner',
        methods: ['GET']
    )]
    #[AclAncestor('genaker_comivoyager_planner')]
    public function indexAction(): Response
    {
        return $this->render('@GenakerComiVoyager/RoutePlanner/index.html.twig', [
            'depotLat'       => $this->configManager->get('genaker_comi_voyager.depot_lat'),
            'depotLng'       => $this->configManager->get('genaker_comi_voyager.depot_lng'),
            'defaultDrivers' => (int) ($this->configManager->get('genaker_comi_voyager.default_drivers') ?: 3),
        ]);
    }
}
