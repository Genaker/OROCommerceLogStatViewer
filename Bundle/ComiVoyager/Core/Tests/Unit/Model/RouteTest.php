<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Model;

use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\Leg;
use Genaker\Bundle\ComiVoyager\Core\Model\Route;
use Genaker\Bundle\ComiVoyager\Core\Model\Stop;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Model\Route
 */
final class RouteTest extends TestCase
{
    private function createRoute(): Route
    {
        $addressA = new Address('A', new Coordinate(0.0, 0.0));
        $addressB = new Address('B', new Coordinate(1.0, 0.0));
        $addressC = new Address('C', new Coordinate(2.0, 0.0));

        $legAB = new Leg(0, 1, 2.0, 2.0);
        $legBC = new Leg(1, 2, 3.0, 5.0);

        $stops = [
            new Stop(1, $addressA, null, isStart: true, isEnd: false),
            new Stop(2, $addressB, $legAB, isStart: false, isEnd: false),
            new Stop(3, $addressC, $legBC, isStart: false, isEnd: true),
        ];

        return new Route($stops, [$legAB, $legBC], 5.0);
    }

    public function testTotalStopsCountsAllStops(): void
    {
        self::assertSame(3, $this->createRoute()->totalStops());
    }

    public function testAverageLegKmDividesTotalByLegCount(): void
    {
        self::assertSame(2.5, $this->createRoute()->averageLegKm());
    }

    public function testAverageLegKmIsZeroWithoutLegs(): void
    {
        $address = new Address('A', new Coordinate(0.0, 0.0));
        $route = new Route([new Stop(1, $address, null, isStart: true, isEnd: true)], [], 0.0);

        self::assertSame(0.0, $route->averageLegKm());
    }

    public function testLongestLegKmReturnsMaximumLegDistance(): void
    {
        self::assertSame(3.0, $this->createRoute()->longestLegKm());
    }

    public function testToArrayIncludesRankAndStatistics(): void
    {
        $route = $this->createRoute();
        $route->rank = 1;
        $route->isShortest = true;
        $route->deltaFromBestKm = 0.0;

        $array = $route->toArray();

        self::assertSame(1, $array['rank']);
        self::assertTrue($array['isShortest']);
        self::assertSame(5.0, $array['totalDistanceKm']);
        self::assertSame(3, $array['totalStops']);
        self::assertSame(2.5, $array['averageLegKm']);
        self::assertSame(3.0, $array['longestLegKm']);
        self::assertSame(0.0, $array['deltaFromBestKm']);
        self::assertCount(3, $array['stops']);
        self::assertCount(2, $array['legs']);
    }
}
