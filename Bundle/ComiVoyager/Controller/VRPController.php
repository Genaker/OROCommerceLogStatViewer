<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Controller;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Provider\DeliveryOrderProviderInterface;
use Genaker\Bundle\ComiVoyager\Provider\OrderQueryCriteria;
use Genaker\Bundle\ComiVoyager\Solver\VRPSolverRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class VRPController extends AbstractController
{
    public function __construct(
        private readonly VRPSolverRegistry $solverRegistry,
        private readonly DeliveryOrderProviderInterface $orderProvider,
    ) {
    }

    #[Route(
        path: '/comivoyager/vrp/optimize',
        name: 'genaker_comivoyager_vrp_optimize',
        methods: ['POST'],
        options: ['expose' => true, 'csrf_protection' => false]
    )]
    public function optimizeAction(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        $depotData = $data['depot'] ?? null;
        if (!isset($depotData['lat'], $depotData['lng'])) {
            return new JsonResponse(['error' => 'depot.lat and depot.lng are required'], 400);
        }
        $depot = new Coordinate((float) $depotData['lat'], (float) $depotData['lng']);

        $ordersData = $data['orders'] ?? [];
        if (empty($ordersData)) {
            return new JsonResponse(['error' => 'orders array is required and must not be empty'], 400);
        }

        $orders = [];
        foreach ($ordersData as $i => $o) {
            if (!isset($o['lat'], $o['lng'])) {
                return new JsonResponse(['error' => "orders[$i].lat and orders[$i].lng are required"], 400);
            }
            $orders[] = new DeliveryOrder(
                (string) ($o['id'] ?? "ORD-$i"),
                new Coordinate((float) $o['lat'], (float) $o['lng']),
                (float) ($o['weight_lbs'] ?? 0),
                (string) ($o['priority'] ?? 'normal'),
                isset($o['customer_id']) ? (string) $o['customer_id'] : null,
            );
        }

        $vehicles = $this->buildVehicles($data, $depot);
        if (empty($vehicles)) {
            return new JsonResponse(['error' => 'At least one vehicle/driver is required'], 400);
        }

        $maxRadius = (float) ($data['max_radius_miles'] ?? 100.0);
        $solverName = isset($data['solver']) ? (string) $data['solver'] : null;

        try {
            $solver = $this->solverRegistry->get($solverName);
            $solution = $solver->solve($orders, $vehicles, $depot, $maxRadius);
            $result = $solution->toArray();
            $result['solver'] = $solver->getName();
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Optimize routes for today's ready-to-ship OroCommerce orders. Instead of
     * passing order coordinates in the body, the orders are pulled from the
     * database (and geocoded) by the DeliveryOrderProvider.
     */
    #[Route(
        path: '/comivoyager/vrp/optimize-orders',
        name: 'genaker_comivoyager_vrp_optimize_orders',
        methods: ['POST'],
        options: ['expose' => true, 'csrf_protection' => false]
    )]
    public function optimizeOrdersAction(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        $depotData = $data['depot'] ?? null;
        if (!isset($depotData['lat'], $depotData['lng'])) {
            return new JsonResponse(['error' => 'depot.lat and depot.lng are required'], 400);
        }
        $depot = new Coordinate((float) $depotData['lat'], (float) $depotData['lng']);

        $vehicles = $this->buildVehicles($data, $depot);
        if (empty($vehicles)) {
            return new JsonResponse(['error' => 'At least one vehicle/driver is required'], 400);
        }

        $criteria = new OrderQueryCriteria(
            statuses: (array) ($data['statuses'] ?? []),
            limit: (int) ($data['limit'] ?? 500),
            createdAfter: isset($data['created_after'])
                ? new \DateTimeImmutable((string) $data['created_after'])
                : null,
        );

        $maxRadius = (float) ($data['max_radius_miles'] ?? 100.0);
        $solverName = isset($data['solver']) ? (string) $data['solver'] : null;

        try {
            $orders = $this->orderProvider->getDeliveryOrders($criteria);
            if (empty($orders)) {
                return new JsonResponse([
                    'routes'  => [],
                    'summary' => ['orders_assigned' => 0, 'note' => 'No matching geocodable orders found'],
                ]);
            }

            $solver = $this->solverRegistry->get($solverName);
            $solution = $solver->solve($orders, $vehicles, $depot, $maxRadius);
            $result = $solution->toArray();
            $result['solver'] = $solver->getName();
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build the vehicle/driver list from the request.
     *
     * Two forms are supported:
     *  - "drivers": [ {...per-driver settings...}, ... ]  — heterogeneous fleet
     *  - "vehicles": { count, ...shared defaults... }      — homogeneous fleet
     *
     * @return Vehicle[]
     */
    private function buildVehicles(array $data, Coordinate $depot): array
    {
        // Heterogeneous fleet: explicit per-driver list.
        if (!empty($data['drivers']) && is_array($data['drivers'])) {
            $vehicles = [];
            foreach ($data['drivers'] as $i => $d) {
                $vehicles[] = new Vehicle(
                    (int) ($d['id'] ?? $i + 1),
                    (float) ($d['capacity_lbs'] ?? 0),
                    (int) ($d['max_stops'] ?? 0),
                    (float) ($d['max_distance_miles'] ?? 0),
                    $this->coord($d['start'] ?? null),
                    $this->coord($d['end'] ?? null),
                    (bool) ($d['return_to_start'] ?? true),
                    (float) ($d['avg_speed_mph'] ?? 30.0),
                    (float) ($d['service_time_minutes'] ?? 10.0),
                    (float) ($d['max_work_hours'] ?? 0),
                );
            }
            return $vehicles;
        }

        // Homogeneous fleet: shared settings, N identical vehicles.
        $v = $data['vehicles'] ?? ['count' => 1];
        $count = max(1, (int) ($v['count'] ?? 1));
        $start = $this->coord($v['start'] ?? null);
        $end = $this->coord($v['end'] ?? null);

        $vehicles = [];
        for ($i = 1; $i <= $count; $i++) {
            $vehicles[] = new Vehicle(
                $i,
                (float) ($v['capacity_lbs'] ?? 0),
                (int) ($v['max_stops'] ?? 0),
                (float) ($v['max_distance_miles'] ?? 0),
                $start,
                $end,
                (bool) ($v['return_to_start'] ?? true),
                (float) ($v['avg_speed_mph'] ?? 30.0),
                (float) ($v['service_time_minutes'] ?? 10.0),
                (float) ($v['max_work_hours'] ?? 0),
            );
        }
        return $vehicles;
    }

    private function coord(?array $data): ?Coordinate
    {
        if (!isset($data['lat'], $data['lng'])) {
            return null;
        }
        return new Coordinate((float) $data['lat'], (float) $data['lng']);
    }
}
