<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Genaker\Bundle\ComiVoyager\Solver\GoogleFleetRoutingSolver;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GoogleFleetRoutingSolverTest extends TestCase
{
    public function testGetName(): void
    {
        $solver = $this->solver();
        self::assertSame('google', $solver->getName());
    }

    public function testBuildRequestContainsShipmentsAndVehicles(): void
    {
        $solver = $this->solver();
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            new DeliveryOrder('ORD-1', new Coordinate(40.75, -73.99), 8000),
            new DeliveryOrder('ORD-2', new Coordinate(40.65, -74.10), 5000),
        ];
        $vehicles = [
            new Vehicle(1, capacityLbs: 40000, maxWorkHours: 8.0, avgSpeedMph: 30.0, serviceTimeMinutes: 15.0),
        ];

        $request = $solver->buildRequest($orders, $vehicles, $depot);

        self::assertArrayHasKey('model', $request);
        self::assertCount(2, $request['model']['shipments']);
        self::assertCount(1, $request['model']['vehicles']);

        // Shipment labels
        self::assertSame('ORD-1', $request['model']['shipments'][0]['label']);
        self::assertSame('ORD-2', $request['model']['shipments'][1]['label']);

        // Weight demand
        self::assertSame('8000', $request['model']['shipments'][0]['loadDemands']['weight_lbs']['amount']);

        // Vehicle start/end
        $v = $request['model']['vehicles'][0];
        self::assertSame(40.71, $v['startWaypoint']['location']['latLng']['latitude']);
        self::assertSame(-74.01, $v['startWaypoint']['location']['latLng']['longitude']);

        // Capacity
        self::assertSame('40000', $v['loadLimits']['weight_lbs']['maxLoad']);

        // Time limit
        self::assertSame('28800s', $v['routeDurationLimit']['maxDuration']);

        // Service time
        self::assertSame('900s', $request['model']['shipments'][0]['deliveries'][0]['duration']);
    }

    public function testBuildRequestWithPerDriverStart(): void
    {
        $solver = $this->solver();
        $depot = new Coordinate(40.71, -74.01);
        $home = new Coordinate(41.0, -73.5);
        $vehicles = [
            new Vehicle(1, startLocation: $home, returnToStart: true),
        ];

        $request = $solver->buildRequest(
            [new DeliveryOrder('A', new Coordinate(40.8, -73.9))],
            $vehicles,
            $depot,
        );

        $v = $request['model']['vehicles'][0];
        self::assertSame(41.0, $v['startWaypoint']['location']['latLng']['latitude']);
        self::assertSame(-73.5, $v['startWaypoint']['location']['latLng']['longitude']);
        // End should also be home (returnToStart)
        self::assertSame(41.0, $v['endWaypoint']['location']['latLng']['latitude']);
    }

    public function testParseResponseBuildsRoutes(): void
    {
        $solver = $this->solver();
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            new DeliveryOrder('ORD-1', new Coordinate(40.75, -73.99), 8000, 'normal', null, '123 Main St'),
            new DeliveryOrder('ORD-2', new Coordinate(40.65, -74.10), 5000, 'normal', null, '456 Oak Ave'),
        ];
        $vehicles = [new Vehicle(1, capacityLbs: 40000, serviceTimeMinutes: 10.0)];

        $googleResponse = [
            'routes' => [[
                'vehicleIndex' => 0,
                'visits' => [
                    ['shipmentIndex' => 0, 'startTime' => '300s'],
                    ['shipmentIndex' => 1, 'startTime' => '1200s'],
                ],
                'transitions' => [
                    ['travelDistanceMeters' => 0],     // depot start
                    ['travelDistanceMeters' => 5000],   // depot → ORD-1
                    ['travelDistanceMeters' => 8000],   // ORD-1 → ORD-2
                    ['travelDistanceMeters' => 6000],   // ORD-2 → depot (return)
                ],
                'metrics' => ['travelDistanceMeters' => 19000],
            ]],
            'skippedShipments' => [],
        ];

        $solution = $solver->parseResponse($googleResponse, $orders, $vehicles, $depot);

        self::assertSame(2, $solution->getTotalOrdersAssigned());
        self::assertSame(1, $solution->getVehiclesUsed());
        self::assertEmpty($solution->getUnassigned());

        $route = $solution->getRoutes()[0];
        self::assertSame(2, $route->getStopCount());
        self::assertSame(['ORD-1', 'ORD-2'], $route->getStopIds());

        // Total distance: 19000m → ~11.8 miles
        self::assertGreaterThan(10, $route->getTotalDistanceMiles());

        // Stop details
        $details = $route->getStopDetails();
        self::assertCount(2, $details);
        self::assertSame(1, $details[0]->sequence);
        self::assertSame('ORD-1', $details[0]->order->getId());
        self::assertGreaterThan(0, $details[0]->legDistanceMiles);
        self::assertGreaterThan(0, $details[0]->arrivalHours);

        // Final return leg
        self::assertGreaterThan(0, $route->getFinalLegMiles());
    }

    public function testParseResponseHandlesSkippedShipments(): void
    {
        $solver = $this->solver();
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            new DeliveryOrder('ORD-1', new Coordinate(40.75, -73.99), 8000),
            new DeliveryOrder('ORD-2', new Coordinate(40.65, -74.10), 50000),
        ];
        $vehicles = [new Vehicle(1, capacityLbs: 40000)];

        $googleResponse = [
            'routes' => [[
                'vehicleIndex' => 0,
                'visits' => [
                    ['shipmentIndex' => 0, 'startTime' => '300s'],
                ],
                'transitions' => [
                    ['travelDistanceMeters' => 0],
                    ['travelDistanceMeters' => 5000],
                    ['travelDistanceMeters' => 4000],
                ],
                'metrics' => ['travelDistanceMeters' => 9000],
            ]],
            'skippedShipments' => [['index' => 1, 'label' => 'ORD-2']],
        ];

        $solution = $solver->parseResponse($googleResponse, $orders, $vehicles, $depot);

        self::assertSame(1, $solution->getTotalOrdersAssigned());
        self::assertCount(1, $solution->getUnassigned());
        self::assertSame('ORD-2', $solution->getUnassigned()[0]->getId());
    }

    public function testSolveFiltersOutOfRange(): void
    {
        $solver = $this->solver(httpResponse: $this->emptyGoogleResponse());
        $depot = new Coordinate(40.71, -74.01);

        $orders = [
            new DeliveryOrder('NEAR', new Coordinate(40.73, -73.99)),
            new DeliveryOrder('FAR', new Coordinate(34.05, -118.24)),
        ];

        $solution = $solver->solve($orders, [new Vehicle(1)], $depot, 100.0);

        self::assertCount(1, $solution->getOutOfRange());
        self::assertSame('FAR', $solution->getOutOfRange()[0]->getId());
    }

    public function testSolveEmptyOrders(): void
    {
        $solver = $this->solver();
        $result = $solver->solve([], [new Vehicle(1)], new Coordinate(40.71, -74.01));
        self::assertEmpty($result->getRoutes());
    }

    public function testSolveThrowsWithoutApiKey(): void
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('');

        $solver = new GoogleFleetRoutingSolver(
            $this->createMock(HttpClientInterface::class),
            $config,
        );

        $this->expectException(DistanceProviderUnavailableException::class);
        $this->expectExceptionMessageMatches('/API key/');
        $solver->solve(
            [new DeliveryOrder('A', new Coordinate(40.75, -73.99))],
            [new Vehicle(1)],
            new Coordinate(40.71, -74.01),
        );
    }

    public function testParseResponseMultipleVehicles(): void
    {
        $solver = $this->solver();
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            new DeliveryOrder('A', new Coordinate(40.75, -73.99), 5000),
            new DeliveryOrder('B', new Coordinate(40.65, -74.10), 5000),
            new DeliveryOrder('C', new Coordinate(40.80, -73.95), 5000),
        ];
        $vehicles = [new Vehicle(1, 40000), new Vehicle(2, 40000)];

        $googleResponse = [
            'routes' => [
                [
                    'visits' => [['shipmentIndex' => 0, 'startTime' => '300s'], ['shipmentIndex' => 2, 'startTime' => '900s']],
                    'transitions' => [['travelDistanceMeters' => 0], ['travelDistanceMeters' => 3000], ['travelDistanceMeters' => 4000], ['travelDistanceMeters' => 5000]],
                    'metrics' => ['travelDistanceMeters' => 12000],
                ],
                [
                    'visits' => [['shipmentIndex' => 1, 'startTime' => '600s']],
                    'transitions' => [['travelDistanceMeters' => 0], ['travelDistanceMeters' => 7000], ['travelDistanceMeters' => 6000]],
                    'metrics' => ['travelDistanceMeters' => 13000],
                ],
            ],
        ];

        $solution = $solver->parseResponse($googleResponse, $orders, $vehicles, $depot);

        self::assertSame(3, $solution->getTotalOrdersAssigned());
        self::assertSame(2, $solution->getVehiclesUsed());
        self::assertSame(2, $solution->getRoutes()[0]->getStopCount());
        self::assertSame(1, $solution->getRoutes()[1]->getStopCount());
    }

    public function testToArrayIncludesSolverName(): void
    {
        $solver = $this->solver();
        $solution = $solver->parseResponse(
            ['routes' => [], 'skippedShipments' => []],
            [],
            [new Vehicle(1)],
            new Coordinate(40.71, -74.01),
        );

        $array = $solution->toArray();
        self::assertArrayHasKey('summary', $array);
    }

    // --- Helpers ---

    private function solver(?ResponseInterface $httpResponse = null): GoogleFleetRoutingSolver
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnMap([
            ['genaker_comi_voyager.google_api_key', false, false, null, 'test-api-key-123'],
            ['genaker_comi_voyager.google_project_id', false, false, null, 'test-project'],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        if ($httpResponse !== null) {
            $httpClient->method('request')->willReturn($httpResponse);
        }

        return new GoogleFleetRoutingSolver($httpClient, $config);
    }

    private function emptyGoogleResponse(): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'routes' => [[
                'visits' => [['shipmentIndex' => 0, 'startTime' => '300s']],
                'transitions' => [['travelDistanceMeters' => 0], ['travelDistanceMeters' => 5000], ['travelDistanceMeters' => 4000]],
                'metrics' => ['travelDistanceMeters' => 9000],
            ]],
        ]);
        return $response;
    }
}
