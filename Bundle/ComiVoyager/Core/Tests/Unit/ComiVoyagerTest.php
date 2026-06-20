<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit;

use Genaker\Bundle\ComiVoyager\Core\ComiVoyager;
use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Exception\InsufficientAddressesException;
use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\ComiVoyager
 */
final class ComiVoyagerTest extends TestCase
{
    /**
     * @return Address[]
     */
    private function addresses(): array
    {
        return [
            new Address('London', new Coordinate(51.5074, -0.1278)),
            new Address('Paris', new Coordinate(48.8566, 2.3522)),
            new Address('Berlin', new Coordinate(52.5200, 13.4050)),
            new Address('Madrid', new Coordinate(40.4168, -3.7038)),
        ];
    }

    public function testOptimizeReturnsDefaultCountOfRoutes(): void
    {
        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());

        $result = $engine->optimize($this->addresses());

        self::assertCount(3, $result->routes);
        self::assertSame(3, $result->requestedCount);
        self::assertTrue($result->routes[0]->isShortest);
        self::assertSame(0, $result->shortestIndex);
    }

    public function testOptimizeRoutesAreSortedAscendingByDistance(): void
    {
        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());

        $result = $engine->optimize($this->addresses());

        $previous = 0.0;

        foreach ($result->routes as $route) {
            self::assertGreaterThanOrEqual($previous, $route->totalDistanceKm);
            $previous = $route->totalDistanceKm;
        }
    }

    public function testOptimizeHonorsCustomRouteCount(): void
    {
        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());

        $result = $engine->optimize($this->addresses(), routes: 1);

        self::assertCount(1, $result->routes);
        self::assertSame(1, $result->requestedCount);
    }

    public function testOptimizeHonorsDepotOption(): void
    {
        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());

        $result = $engine->optimize($this->addresses(), options: new SolveOptions(depotIndex: 2));

        foreach ($result->routes as $route) {
            self::assertSame('Berlin', $route->stops[0]->address->label);
        }
    }

    public function testOptimizeThrowsForFewerThanTwoAddresses(): void
    {
        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());

        $this->expectException(InsufficientAddressesException::class);

        $engine->optimize([new Address('Only', new Coordinate(0.0, 0.0))]);
    }
}
