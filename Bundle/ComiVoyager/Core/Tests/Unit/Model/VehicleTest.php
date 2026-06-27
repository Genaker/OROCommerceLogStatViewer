<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Model;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use PHPUnit\Framework\TestCase;

class VehicleTest extends TestCase
{
    public function testDefaults(): void
    {
        $v = new Vehicle(1);
        self::assertSame(1, $v->getId());
        self::assertSame(0.0, $v->getCapacityLbs());
        self::assertSame(30.0, $v->getAvgSpeedMph());
        self::assertSame(10.0, $v->getServiceTimeMinutes());
        self::assertSame(0.0, $v->getMaxWorkHours());
        self::assertTrue($v->shouldReturnToStart());
        self::assertFalse($v->hasTimeLimit());
        self::assertFalse($v->hasDistanceLimit());
    }

    public function testResolveStartFallsBackToDepot(): void
    {
        $depot = new Coordinate(40.0, -74.0);
        $v = new Vehicle(1);
        self::assertSame($depot, $v->resolveStart($depot));
    }

    public function testResolveStartUsesOwnLocation(): void
    {
        $depot = new Coordinate(40.0, -74.0);
        $home = new Coordinate(41.0, -73.0);
        $v = new Vehicle(1, startLocation: $home);
        self::assertSame($home, $v->resolveStart($depot));
    }

    public function testResolveEndRoundTrip(): void
    {
        $depot = new Coordinate(40.0, -74.0);
        $v = new Vehicle(1, returnToStart: true);
        self::assertSame($depot, $v->resolveEnd($depot));
    }

    public function testResolveEndOpenRoute(): void
    {
        $depot = new Coordinate(40.0, -74.0);
        $v = new Vehicle(1, returnToStart: false);
        self::assertNull($v->resolveEnd($depot));
    }

    public function testResolveEndExplicit(): void
    {
        $depot = new Coordinate(40.0, -74.0);
        $home = new Coordinate(41.0, -73.0);
        $v = new Vehicle(1, endLocation: $home, returnToStart: false);
        self::assertSame($home, $v->resolveEnd($depot));
    }

    public function testEstimateHours(): void
    {
        // 60 miles at 30 mph = 2h driving; 3 stops * 10 min = 0.5h service
        $v = new Vehicle(1, avgSpeedMph: 30.0, serviceTimeMinutes: 10.0);
        self::assertEqualsWithDelta(2.5, $v->estimateHours(60.0, 3), 0.001);
    }

    public function testEstimateHoursZeroDistance(): void
    {
        $v = new Vehicle(1, avgSpeedMph: 30.0, serviceTimeMinutes: 15.0);
        // 0 miles + 2 stops * 15 min = 0.5h
        self::assertEqualsWithDelta(0.5, $v->estimateHours(0.0, 2), 0.001);
    }

    public function testSpeedFloor(): void
    {
        // avgSpeed of 0 would divide-by-zero; clamped to 1
        $v = new Vehicle(1, avgSpeedMph: 0.0);
        self::assertSame(1.0, $v->getAvgSpeedMph());
    }

    public function testLimitFlags(): void
    {
        $v = new Vehicle(1, capacityLbs: 40000, maxStops: 10, maxDistanceMiles: 200, maxWorkHours: 8);
        self::assertTrue($v->hasCapacityLimit());
        self::assertTrue($v->hasStopLimit());
        self::assertTrue($v->hasDistanceLimit());
        self::assertTrue($v->hasTimeLimit());
    }
}
