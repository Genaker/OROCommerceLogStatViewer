<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Model;

use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\Route;
use Genaker\Bundle\ComiVoyager\Core\Model\RouteCollection;
use Genaker\Bundle\ComiVoyager\Core\Model\Stop;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Model\RouteCollection
 */
final class RouteCollectionTest extends TestCase
{
    public function testToArrayIncludesRoutesAndMetadata(): void
    {
        $address = new Address('A', new Coordinate(0.0, 0.0));
        $stop = new Stop(1, $address, null, isStart: true, isEnd: true);

        $routeOne = new Route([$stop], [], 1.0, rank: 1, isShortest: true, deltaFromBestKm: 0.0);
        $routeTwo = new Route([$stop], [], 2.0, rank: 2, isShortest: false, deltaFromBestKm: 1.0);

        $collection = new RouteCollection([$routeOne, $routeTwo], shortestIndex: 0, requestedCount: 3);

        $array = $collection->toArray();

        self::assertCount(2, $array['routes']);
        self::assertSame(0, $array['shortestIndex']);
        self::assertSame(3, $array['requestedCount']);
        self::assertSame(1.0, $array['routes'][0]['totalDistanceKm']);
        self::assertSame(2.0, $array['routes'][1]['totalDistanceKm']);
    }
}
