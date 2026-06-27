<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Clustering;

use Genaker\Bundle\ComiVoyager\Core\Clustering\CapacityAdjuster;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use PHPUnit\Framework\TestCase;

class CapacityAdjusterTest extends TestCase
{
    private CapacityAdjuster $adjuster;

    protected function setUp(): void
    {
        $this->adjuster = new CapacityAdjuster();
    }

    public function testNoConstraintsPassesThrough(): void
    {
        $orders = [
            $this->order('A', 40.71, -74.01, 5000),
            $this->order('B', 40.73, -73.99, 8000),
        ];
        $vehicles = [$this->vehicle(1, 0)]; // no capacity limit

        $result = $this->adjuster->adjust([0 => $orders], $vehicles);

        self::assertCount(1, $result['routes']);
        self::assertSame(2, $result['routes'][0]->getStopCount());
        self::assertEmpty($result['unassigned']);
        self::assertEmpty($result['out_of_range']);
    }

    public function testCapacityOverflowRedistributes(): void
    {
        $orders1 = [
            $this->order('A', 40.71, -74.01, 20000),
            $this->order('B', 40.72, -74.00, 25000),
        ];
        $orders2 = [
            $this->order('C', 40.80, -73.95, 5000),
        ];

        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
        ];

        $result = $this->adjuster->adjust([0 => $orders1, 1 => $orders2], $vehicles);

        $totalStops = array_sum(array_map(fn($r) => $r->getStopCount(), $result['routes']));
        self::assertSame(3, $totalStops);
        self::assertEmpty($result['unassigned']);

        // Each route should respect capacity
        foreach ($result['routes'] as $route) {
            self::assertLessThanOrEqual(40000, $route->getTotalWeightLbs());
        }
    }

    public function testRadiusFilterExcludesDistantOrders(): void
    {
        $depot = new Coordinate(40.71, -74.01); // NYC
        $orders = [
            $this->order('Near', 40.73, -73.99, 5000),   // ~1.5 miles
            $this->order('Far', 34.05, -118.24, 5000),    // ~2,450 miles (LA)
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->adjuster->adjust([0 => $orders], $vehicles, $depot, 100.0);

        self::assertCount(1, $result['routes']);
        self::assertSame(1, $result['routes'][0]->getStopCount());
        self::assertCount(1, $result['out_of_range']);
        self::assertSame('Far', $result['out_of_range'][0]->getId());
    }

    public function testRadiusZeroDisablesFilter(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('Far', 34.05, -118.24, 5000),
        ];
        $vehicles = [$this->vehicle(1, 0)];

        $result = $this->adjuster->adjust([0 => $orders], $vehicles, $depot, 0.0);

        self::assertSame(1, $result['routes'][0]->getStopCount());
        self::assertEmpty($result['out_of_range']);
    }

    public function testUrgentOrdersGetPriority(): void
    {
        $orders = [
            $this->order('Normal1', 40.71, -74.01, 20000, 'normal'),
            $this->order('Urgent1', 40.72, -74.00, 20000, 'urgent'),
            $this->order('Normal2', 40.73, -73.99, 20000, 'normal'),
        ];

        // Only room for 2 orders (40000 lbs capacity)
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->adjuster->adjust([0 => $orders], $vehicles);

        $route = $result['routes'][0];
        self::assertSame(2, $route->getStopCount());

        // Urgent should be in the route
        $ids = $route->getStopIds();
        self::assertContains('Urgent1', $ids);
    }

    public function testMaxStopsConstraint(): void
    {
        $orders = [
            $this->order('A', 40.71, -74.01, 100),
            $this->order('B', 40.72, -74.00, 100),
            $this->order('C', 40.73, -73.99, 100),
            $this->order('D', 40.74, -73.98, 100),
        ];

        $vehicles = [
            new Vehicle(1, 0, 2), // max 2 stops
            new Vehicle(2, 0, 2),
        ];

        $result = $this->adjuster->adjust([0 => $orders, 1 => []], $vehicles);

        foreach ($result['routes'] as $route) {
            self::assertLessThanOrEqual(2, $route->getStopCount());
        }

        $totalAssigned = array_sum(array_map(fn($r) => $r->getStopCount(), $result['routes']));
        self::assertSame(4, $totalAssigned);
    }

    public function testUnassignedWhenNoCapacity(): void
    {
        $orders = [
            $this->order('A', 40.71, -74.01, 30000),
            $this->order('B', 40.72, -74.00, 30000),
            $this->order('C', 40.73, -73.99, 30000),
        ];

        $vehicles = [$this->vehicle(1, 40000)]; // room for ~1 order

        $result = $this->adjuster->adjust([0 => $orders], $vehicles);

        self::assertGreaterThan(0, count($result['unassigned']));
        self::assertSame(1, $result['routes'][0]->getStopCount());
    }

    public function testEmptyInput(): void
    {
        $result = $this->adjuster->adjust([], []);
        self::assertEmpty($result['routes']);
    }

    private function order(string $id, float $lat, float $lng, float $weight = 0.0, string $priority = 'normal'): DeliveryOrder
    {
        return new DeliveryOrder($id, new Coordinate($lat, $lng), $weight, $priority);
    }

    private function vehicle(int $id, float $capacity): Vehicle
    {
        return new Vehicle($id, $capacity);
    }
}
