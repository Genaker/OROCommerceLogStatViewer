<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Solver\VRPSolver;
use PHPUnit\Framework\TestCase;

class VRPSolverTest extends TestCase
{
    private VRPSolver $solver;

    protected function setUp(): void
    {
        $this->solver = new VRPSolver(new HaversineDistanceMatrixProvider());
    }

    public function testEmptyInput(): void
    {
        $result = $this->solver->solve([], [], new Coordinate(40.71, -74.01));
        self::assertEmpty($result->getRoutes());
        self::assertSame(0, $result->getTotalOrdersAssigned());
    }

    public function testSingleVehicleSingleOrder(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [$this->order('A', 40.73, -73.99, 5000)];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(1, $result->getVehiclesUsed());
        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
    }

    public function testSingleVehicleMultipleOrders(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 5000),
            $this->order('B', 40.73, -73.99, 8000),
            $this->order('C', 40.74, -73.98, 6000),
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(1, $result->getVehiclesUsed());
        self::assertSame(3, $result->getTotalOrdersAssigned());
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
        self::assertEmpty($result->getUnassigned());
    }

    public function testTwoVehiclesSplitGeographically(): void
    {
        $depot = new Coordinate(40.71, -74.01);

        // North group
        $orders = [
            $this->order('N1', 40.90, -73.90, 5000),
            $this->order('N2', 40.92, -73.88, 5000),
            $this->order('N3', 40.94, -73.86, 5000),
            // South group
            $this->order('S1', 40.50, -74.20, 5000),
            $this->order('S2', 40.48, -74.22, 5000),
            $this->order('S3', 40.46, -74.24, 5000),
        ];
        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
        ];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(2, $result->getVehiclesUsed());
        self::assertSame(6, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getUnassigned());

        // Each route should have 3 stops
        foreach ($result->getRoutes() as $route) {
            self::assertSame(3, $route->getStopCount());
        }
    }

    public function testCapacityConstraintCreatesUnassigned(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 25000),
            $this->order('B', 40.73, -73.99, 25000),
            $this->order('C', 40.74, -73.98, 25000),
        ];
        // Only one truck with 40,000 lbs capacity — can only fit 1 order
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertGreaterThan(0, count($result->getUnassigned()));
    }

    public function testRadiusFilterExcludesDistantOrders(): void
    {
        $depot = new Coordinate(40.71, -74.01); // NYC
        $orders = [
            $this->order('Near', 40.73, -73.99, 5000),
            $this->order('Far', 42.36, -71.06, 5000), // Boston ~190 miles
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot, 100.0);

        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertCount(1, $result->getOutOfRange());
        self::assertSame('Far', $result->getOutOfRange()[0]->getId());
    }

    public function testRadiusZeroAllowsAll(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('Far', 42.36, -71.06, 5000),
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot, 0.0);

        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getOutOfRange());
    }

    public function testThreeVehiclesBalanced(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [];
        // 3 clusters of 4 orders each in different directions
        foreach ([0.15, -0.15, 0.0] as $gi => $latOffset) {
            for ($i = 0; $i < 4; $i++) {
                $lat = 40.71 + $latOffset + $i * 0.01;
                $lng = -74.01 + ($gi - 1) * 0.15 + $i * 0.01;
                $orders[] = $this->order("G{$gi}O{$i}", $lat, $lng, 5000);
            }
        }

        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
            $this->vehicle(3, 40000),
        ];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(12, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getUnassigned());
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
    }

    public function testSolutionToArray(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 5000),
            $this->order('B', 40.73, -73.99, 8000),
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);
        $array = $result->toArray();

        self::assertArrayHasKey('routes', $array);
        self::assertArrayHasKey('summary', $array);
        self::assertSame(2, $array['summary']['orders_assigned']);
        self::assertSame(0, $array['summary']['orders_unassigned']);
        self::assertGreaterThan(0, $array['summary']['total_distance_miles']);
    }

    public function testTenOrdersTwoTrucks(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [];
        for ($i = 0; $i < 10; $i++) {
            $angle = $i * (2 * M_PI / 10);
            $lat = 40.71 + 0.1 * cos($angle);
            $lng = -74.01 + 0.1 * sin($angle);
            $orders[] = $this->order("ORD-$i", $lat, $lng, 3000);
        }

        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
        ];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(10, $result->getTotalOrdersAssigned());
        self::assertSame(2, $result->getVehiclesUsed());

        // Total distance with 2 trucks should be reasonable
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
        self::assertLessThan(200, $result->getTotalDistanceMiles());
    }

    public function testWorkHoursBudgetTrimsStops(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        // Spread stops over a wide area so the route is long
        $orders = [];
        for ($i = 0; $i < 8; $i++) {
            $orders[] = $this->order("O$i", 40.71 + $i * 0.15, -74.01 + $i * 0.15, 1000);
        }

        // Very short shift: 1 hour at 30 mph, 10 min/stop — can only do a couple stops
        $vehicle = new Vehicle(
            1,
            capacityLbs: 40000,
            avgSpeedMph: 30.0,
            serviceTimeMinutes: 10.0,
            maxWorkHours: 1.0,
        );

        $result = $this->solver->solve($orders, [$vehicle], $depot);

        // Some orders must be dropped to fit the 1-hour shift
        self::assertGreaterThan(0, count($result->getUnassigned()));

        // The assigned route must respect the time budget
        foreach ($result->getRoutes() as $route) {
            if ($route->getStopCount() > 0) {
                self::assertLessThanOrEqual(1.0, $route->getTotalDurationHours());
            }
        }
    }

    public function testMaxDistanceBudgetTrimsStops(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [];
        for ($i = 0; $i < 6; $i++) {
            $orders[] = $this->order("O$i", 40.71 + $i * 0.2, -74.01, 1000);
        }

        // Tight 20-mile distance cap
        $vehicle = new Vehicle(1, capacityLbs: 40000, maxDistanceMiles: 20.0);

        $result = $this->solver->solve($orders, [$vehicle], $depot);

        self::assertGreaterThan(0, count($result->getUnassigned()));
        foreach ($result->getRoutes() as $route) {
            if ($route->getStopCount() > 0) {
                self::assertLessThanOrEqual(20.0, $route->getTotalDistanceMiles());
            }
        }
    }

    public function testGenerousWorkHoursAssignsAll(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 1000),
            $this->order('B', 40.73, -73.99, 1000),
            $this->order('C', 40.74, -73.98, 1000),
        ];
        $vehicle = new Vehicle(1, capacityLbs: 40000, maxWorkHours: 24.0);

        $result = $this->solver->solve($orders, [$vehicle], $depot);

        self::assertSame(3, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getUnassigned());
    }

    public function testPerDriverStartLocation(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 41.50, -73.50, 1000),
            $this->order('B', 41.52, -73.48, 1000),
        ];

        // Driver starts from their own home, near the orders (not the depot)
        $home = new Coordinate(41.51, -73.49);
        $vehicle = new Vehicle(1, capacityLbs: 40000, startLocation: $home);

        $result = $this->solver->solve($orders, [$vehicle], $depot);

        self::assertSame(2, $result->getTotalOrdersAssigned());
        // Distance from the nearby home should be small (< 20 mi round trip)
        self::assertLessThan(20.0, $result->getRoutes()[0]->getTotalDistanceMiles());
    }

    public function testOneWayRouteShorterThanRoundTrip(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.80, -73.90, 1000),
            $this->order('B', 40.90, -73.80, 1000),
            $this->order('C', 41.00, -73.70, 1000),
        ];

        $roundTrip = $this->solver->solve($orders, [new Vehicle(1, 40000, returnToStart: true)], $depot);
        $oneWay = $this->solver->solve($orders, [new Vehicle(1, 40000, returnToStart: false)], $depot);

        $rtDist = $roundTrip->getRoutes()[0]->getTotalDistanceMiles();
        $owDist = $oneWay->getRoutes()[0]->getTotalDistanceMiles();

        self::assertGreaterThan($owDist, $rtDist);
    }

    public function testDurationReportedInSummary(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 1000),
            $this->order('B', 40.73, -73.99, 1000),
        ];
        $vehicle = new Vehicle(1, capacityLbs: 40000, avgSpeedMph: 30.0, serviceTimeMinutes: 15.0);

        $result = $this->solver->solve($orders, [$vehicle], $depot);
        $array = $result->toArray();

        self::assertArrayHasKey('total_duration_hours', $array['routes'][0]);
        self::assertArrayHasKey('max_route_duration_hours', $array['summary']);
        // 2 stops * 15 min service = 0.5h minimum, plus driving
        self::assertGreaterThanOrEqual(0.5, $array['routes'][0]['total_duration_hours']);
    }

    public function testStopDetailsHaveSequentialEtas(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.75, -73.95, 1000),
            $this->order('B', 40.78, -73.92, 1000),
            $this->order('C', 40.80, -73.90, 1000),
        ];
        $vehicle = new Vehicle(1, capacityLbs: 40000, avgSpeedMph: 30.0, serviceTimeMinutes: 12.0);

        $result = $this->solver->solve($orders, [$vehicle], $depot);
        $route = $result->getRoutes()[0];
        $details = $route->getStopDetails();

        self::assertCount(3, $details);

        // Sequence numbers are 1, 2, 3
        self::assertSame([1, 2, 3], array_map(fn($d) => $d->sequence, $details));

        // Arrival times strictly increase
        $prev = -1.0;
        foreach ($details as $d) {
            self::assertGreaterThan($prev, $d->arrivalHours);
            // departure = arrival + 12 min service
            self::assertEqualsWithDelta($d->arrivalHours + 12.0 / 60.0, $d->departureHours, 0.0001);
            $prev = $d->departureHours;
        }

        // Cumulative distance is monotonic and ends below total
        $lastCumulative = end($details)->cumulativeDistanceMiles;
        self::assertLessThanOrEqual($route->getTotalDistanceMiles() + 0.01, $lastCumulative);
    }

    public function testFirstStopLegFromStart(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [$this->order('A', 40.75, -73.99, 1000)];
        $vehicle = new Vehicle(1, capacityLbs: 40000, avgSpeedMph: 30.0, serviceTimeMinutes: 0.0);

        $result = $this->solver->solve($orders, [$vehicle], $depot);
        $details = $result->getRoutes()[0]->getStopDetails();

        self::assertCount(1, $details);
        // Leg distance from depot to the single stop is > 0
        self::assertGreaterThan(0, $details[0]->legDistanceMiles);
        // First stop's cumulative distance equals its leg distance
        self::assertEqualsWithDelta($details[0]->legDistanceMiles, $details[0]->cumulativeDistanceMiles, 0.0001);
        // arrival = legMiles / 30 mph, no service time
        self::assertEqualsWithDelta($details[0]->legDistanceMiles / 30.0, $details[0]->arrivalHours, 0.0001);
    }

    public function testEtaConsistentWithTotalDuration(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 1000),
            $this->order('B', 40.74, -73.98, 1000),
        ];
        $vehicle = new Vehicle(1, capacityLbs: 40000, avgSpeedMph: 25.0, serviceTimeMinutes: 10.0, returnToStart: false);

        $result = $this->solver->solve($orders, [$vehicle], $depot);
        $route = $result->getRoutes()[0];
        $details = $route->getStopDetails();

        // For an open route, the last stop's departure (arrival + service)
        // should equal the route's total duration (no return leg).
        $lastDeparture = end($details)->departureHours;
        self::assertEqualsWithDelta($route->getTotalDurationHours(), $lastDeparture, 0.001);
    }

    public function testStopDetailsInToArray(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [$this->order('ORD-1', 40.72, -74.00, 5000)];
        $vehicle = new Vehicle(1, capacityLbs: 40000);

        $array = $this->solver->solve($orders, [$vehicle], $depot)->toArray();
        $stopDetails = $array['routes'][0]['stop_details'];

        self::assertCount(1, $stopDetails);
        self::assertSame('ORD-1', $stopDetails[0]['order_id']);
        self::assertArrayHasKey('eta_minutes', $stopDetails[0]);
        self::assertArrayHasKey('leg_distance_miles', $stopDetails[0]);
        self::assertArrayHasKey('cumulative_distance_miles', $stopDetails[0]);
        self::assertArrayHasKey('return_leg_miles', $array['routes'][0]);
    }

    private function order(string $id, float $lat, float $lng, float $weight = 0.0): DeliveryOrder
    {
        return new DeliveryOrder($id, new Coordinate($lat, $lng), $weight);
    }

    private function vehicle(int $id, float $capacity): Vehicle
    {
        return new Vehicle($id, $capacity);
    }
}
