<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Integration;

use Genaker\Bundle\ComiVoyager\Controller\RoutePlannerController;
use Genaker\Bundle\ComiVoyager\Controller\VRPController;
use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Integration tests using LocalIntegrationTests — boots the real dev kernel,
 * exercises VRP features through the live container and real DB.
 *
 * Run:
 *   INTEGRATION_TESTS_ENABLED=1 php bin/phpunit -c phpunit-dev.xml \
 *     --filter VRPIntegrationTest --no-coverage
 */
class VRPIntegrationTest extends IntegrationTestCase
{
    // --- Service wiring ---

    public function testVrpControllerIsWired(): void
    {
        $controller = static::getContainer()->get(VRPController::class);
        self::assertInstanceOf(VRPController::class, $controller);
    }

    public function testPlannerControllerIsWired(): void
    {
        $controller = static::getContainer()->get(RoutePlannerController::class);
        self::assertInstanceOf(RoutePlannerController::class, $controller);
    }

    // --- Routes registered ---

    public function testRoutesAreRegistered(): void
    {
        $router = static::getContainer()->get('router');
        $collection = $router->getRouteCollection();

        self::assertNotNull($collection->get('genaker_comivoyager_vrp_optimize'));
        self::assertNotNull($collection->get('genaker_comivoyager_vrp_optimize_orders'));
        self::assertNotNull($collection->get('genaker_comivoyager_planner'));
    }

    public function testOptimizeRouteHasCsrfDisabled(): void
    {
        $router = static::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('genaker_comivoyager_vrp_optimize');
        self::assertFalse($route->getOption('csrf_protection'));

        $route2 = $router->getRouteCollection()->get('genaker_comivoyager_vrp_optimize_orders');
        self::assertFalse($route2->getOption('csrf_protection'));
    }

    // --- Full stack via controller (bypasses auth firewall) ---

    public function testOptimizeActionReturnsRoutes(): void
    {
        $data = $this->callOptimize([
            'depot'    => ['lat' => 40.7128, 'lng' => -74.0060],
            'vehicles' => ['count' => 2, 'capacity_lbs' => 40000, 'max_work_hours' => 8],
            'orders'   => [
                ['id' => 'ORD-1', 'lat' => 40.75, 'lng' => -73.99, 'weight_lbs' => 8000],
                ['id' => 'ORD-2', 'lat' => 40.76, 'lng' => -73.98, 'weight_lbs' => 12000],
                ['id' => 'ORD-3', 'lat' => 40.65, 'lng' => -74.10, 'weight_lbs' => 9000],
                ['id' => 'ORD-4', 'lat' => 40.66, 'lng' => -74.12, 'weight_lbs' => 7000],
            ],
        ]);

        self::assertSame(4, $data['summary']['orders_assigned']);
        self::assertSame(2, $data['summary']['vehicles_used']);
        self::assertGreaterThan(0, $data['summary']['total_distance_miles']);

        $firstRoute = $data['routes'][0];
        self::assertArrayHasKey('stop_details', $firstRoute);
        self::assertNotEmpty($firstRoute['stop_details']);
        self::assertArrayHasKey('eta_minutes', $firstRoute['stop_details'][0]);
        self::assertArrayHasKey('leg_distance_miles', $firstRoute['stop_details'][0]);
        self::assertArrayHasKey('address', $firstRoute['stop_details'][0]);
        self::assertArrayHasKey('total_duration_hours', $firstRoute);
        self::assertArrayHasKey('return_leg_miles', $firstRoute);
        self::assertArrayHasKey('max_route_duration_hours', $data['summary']);
    }

    public function testOptimizeValidatesDepot(): void
    {
        $controller = static::getContainer()->get(VRPController::class);
        $response = $controller->optimizeAction($this->jsonRequest(['orders' => []]));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertStringContainsString('depot', $body['error']);
    }

    public function testOptimizeRespectsRadius(): void
    {
        $data = $this->callOptimize([
            'depot'            => ['lat' => 40.7128, 'lng' => -74.0060],
            'max_radius_miles' => 50,
            'vehicles'         => ['count' => 1, 'capacity_lbs' => 40000],
            'orders'           => [
                ['id' => 'NEAR', 'lat' => 40.75, 'lng' => -73.99, 'weight_lbs' => 1000],
                ['id' => 'FAR',  'lat' => 42.36, 'lng' => -71.06, 'weight_lbs' => 1000],
            ],
        ]);

        self::assertSame(1, $data['summary']['orders_out_of_range']);
        self::assertSame(1, $data['summary']['orders_assigned']);
    }

    public function testWorkHoursBudgetTrimsStops(): void
    {
        $data = $this->callOptimize([
            'depot'    => ['lat' => 40.7128, 'lng' => -74.0060],
            'vehicles' => ['count' => 1, 'capacity_lbs' => 100000, 'max_work_hours' => 0.2,
                           'avg_speed_mph' => 30, 'service_time_minutes' => 5],
            'orders'   => [
                ['id' => 'O1', 'lat' => 40.80, 'lng' => -73.90, 'weight_lbs' => 100],
                ['id' => 'O2', 'lat' => 40.90, 'lng' => -73.80, 'weight_lbs' => 100],
                ['id' => 'O3', 'lat' => 41.00, 'lng' => -73.70, 'weight_lbs' => 100],
                ['id' => 'O4', 'lat' => 41.10, 'lng' => -73.60, 'weight_lbs' => 100],
            ],
        ]);

        self::assertGreaterThan(0, $data['summary']['orders_unassigned']);
    }

    public function testHeterogeneousDrivers(): void
    {
        $data = $this->callOptimize([
            'depot'   => ['lat' => 40.7128, 'lng' => -74.0060],
            'drivers' => [
                ['id' => 1, 'capacity_lbs' => 40000, 'start' => ['lat' => 40.90, 'lng' => -73.90]],
                ['id' => 2, 'capacity_lbs' => 26000, 'return_to_start' => false],
            ],
            'orders' => [
                ['id' => 'A', 'lat' => 40.75, 'lng' => -73.99, 'weight_lbs' => 5000],
                ['id' => 'B', 'lat' => 40.65, 'lng' => -74.10, 'weight_lbs' => 5000],
            ],
        ]);

        self::assertSame(2, $data['summary']['orders_assigned']);
    }

    // --- Order-backed endpoint (real DB, no geocoding) ---

    public function testOptimizeOrdersWithNoMatchingStatus(): void
    {
        $controller = static::getContainer()->get(VRPController::class);
        $response = $controller->optimizeOrdersAction($this->jsonRequest([
            'depot'    => ['lat' => 40.7128, 'lng' => -74.0060],
            'statuses' => ['__no_such_status__'],
            'limit'    => 5,
            'vehicles' => ['count' => 1, 'capacity_lbs' => 40000],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame(0, $data['summary']['orders_assigned']);
    }

    // --- Solver registry ---

    public function testSolverRegistryIsWired(): void
    {
        $registry = static::getContainer()->get(\Genaker\Bundle\ComiVoyager\Solver\VRPSolverRegistry::class);
        self::assertContains('local', $registry->getAvailableNames());
        self::assertContains('google', $registry->getAvailableNames());
    }

    public function testOptimizeWithExplicitLocalSolver(): void
    {
        $data = $this->callOptimize([
            'depot'    => ['lat' => 40.7128, 'lng' => -74.0060],
            'solver'   => 'local',
            'vehicles' => ['count' => 1, 'capacity_lbs' => 40000],
            'orders'   => [
                ['id' => 'A', 'lat' => 40.75, 'lng' => -73.99, 'weight_lbs' => 5000],
            ],
        ]);

        self::assertSame(1, $data['summary']['orders_assigned']);
        self::assertSame('local', $data['solver']);
    }

    public function testGoogleFleetRoutingSolverIsWired(): void
    {
        $solver = static::getContainer()->get(\Genaker\Bundle\ComiVoyager\Solver\GoogleFleetRoutingSolver::class);
        self::assertSame('google', $solver->getName());
    }

    // --- Planner page (route resolves) ---

    public function testPlannerRouteResolves(): void
    {
        $router = static::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('genaker_comivoyager_planner');

        self::assertNotNull($route);
        self::assertSame('/admin/comivoyager/planner', $route->getPath());
    }

    // --- Helpers ---

    private function callOptimize(array $payload): array
    {
        $controller = static::getContainer()->get(VRPController::class);
        $response = $controller->optimizeAction($this->jsonRequest($payload));

        self::assertSame(200, $response->getStatusCode(),
            'VRP optimize returned ' . $response->getStatusCode() . ': ' . $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function jsonRequest(array $payload): Request
    {
        return new Request([], [], [], [], [], [], json_encode($payload));
    }
}
