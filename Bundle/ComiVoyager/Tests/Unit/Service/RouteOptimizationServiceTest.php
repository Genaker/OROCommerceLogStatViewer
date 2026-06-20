<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Distance\DistanceProviderRegistry;
use Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry;
use Genaker\Bundle\ComiVoyager\Service\RouteOptimizationService;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Service\RouteOptimizationService
 */
final class RouteOptimizationServiceTest extends TestCase
{
    private function service(?int $maxAddresses): RouteOptimizationService
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')
            ->willReturnCallback(static fn (string $key) => match ($key) {
                'genaker_comi_voyager.max_addresses' => $maxAddresses,
                'genaker_comi_voyager.default_route_count' => 3,
                default => null,
            });

        $distanceProviderRegistry = new DistanceProviderRegistry(
            [new HaversineDistanceMatrixProvider()],
            $configManager
        );
        $geocoderRegistry = new GeocoderRegistry([], $configManager, $this->createMock(EntityManagerInterface::class));

        return new RouteOptimizationService($distanceProviderRegistry, $geocoderRegistry, $configManager);
    }

    private function address(float|string $lat, float|string $lng, string $label = 'Stop'): array
    {
        return ['label' => $label, 'lat' => $lat, 'lng' => $lng];
    }

    public function testThrowsWhenAddressCountExceedsConfiguredMaxAddresses(): void
    {
        $service = $this->service(2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many addresses: 3 given, maximum is 2.');

        $service->optimize([
            $this->address(40.0, -74.0),
            $this->address(41.0, -75.0),
            $this->address(42.0, -76.0),
        ]);
    }

    public function testAllowsAddressCountAtConfiguredMaxAddresses(): void
    {
        $service = $this->service(2);

        $routes = $service->optimize([
            $this->address(40.0, -74.0),
            $this->address(41.0, -75.0),
        ], routes: 1);

        self::assertNotEmpty($routes->routes);
    }

    public function testFallsBackToDefaultMaxAddressesWhenNotConfigured(): void
    {
        $service = $this->service(null);

        $routes = $service->optimize([
            $this->address(40.0, -74.0),
            $this->address(41.0, -75.0),
        ], routes: 1);

        self::assertNotEmpty($routes->routes);
    }

    public function testThrowsForNonNumericLatitude(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Address at position 0 has non-numeric "lat"/"lng".');

        $service->optimize([
            $this->address('abc', -74.0),
            $this->address(41.0, -75.0),
        ]);
    }

    public function testThrowsForNonNumericLongitude(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Address at position 1 has non-numeric "lat"/"lng".');

        $service->optimize([
            $this->address(40.0, -74.0),
            $this->address(41.0, 'xyz'),
        ]);
    }

    public function testAcceptsNumericStringLatLng(): void
    {
        $service = $this->service(50);

        $routes = $service->optimize([
            $this->address('40.7128', '-74.0060'),
            $this->address('34.0522', '-118.2437'),
        ], routes: 1);

        self::assertNotEmpty($routes->routes);
    }

    public function testThrowsForOutOfRangeLatitudeViaCoordinateValidation(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);

        $service->optimize([
            $this->address(123.456, -74.0),
            $this->address(41.0, -75.0),
        ]);
    }

    /**
     * Regression: a depot index beyond the address count was silently
     * accepted and caused the solver to return zero routes (PHP warnings
     * when accessing $top[0] in an empty candidates array) instead of a 400
     * error. Now validated early with a clear message.
     */
    public function testThrowsForDepotIndexOutOfRange(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depot index 99 is out of range [0, 2).');

        $service->optimize(
            [
                $this->address(40.0, -74.0),
                $this->address(41.0, -75.0),
            ],
            options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 99)
        );
    }

    public function testThrowsForNegativeDepotIndex(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depot index -1 is out of range [0, 2).');

        $service->optimize(
            [
                $this->address(40.0, -74.0),
                $this->address(41.0, -75.0),
            ],
            options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: -1)
        );
    }

    public function testThrowsForDepotIndexEqualToAddressCount(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depot index 2 is out of range [0, 2).');

        $service->optimize(
            [
                $this->address(40.0, -74.0),
                $this->address(41.0, -75.0),
            ],
            options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 2)
        );
    }

    public function testThrowsForDepotIndexOutOfRangeWithManyAddresses(): void
    {
        $service = $this->service(50);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depot index 9 is out of range [0, 5).');

        $addresses = [];
        for ($i = 0; $i < 5; $i++) {
            $addresses[] = $this->address(40.0 + $i, -74.0 - $i);
        }

        $service->optimize($addresses, options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 9));
    }

    public function testAcceptsValidDepotIndexAtBoundary(): void
    {
        $service = $this->service(50);

        // depot=1 is valid for 2 addresses (indices 0 and 1)
        $routes = $service->optimize(
            [
                $this->address(40.0, -74.0, 'First'),
                $this->address(41.0, -75.0, 'Second'),
            ],
            routes: 1,
            options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 1)
        );

        self::assertNotEmpty($routes->routes);
        self::assertSame('Second', $routes->routes[0]->stops[0]->address->label, 'second address must be first in the route');
    }

    public function testAcceptsValidDepotIndexZero(): void
    {
        $service = $this->service(50);

        $routes = $service->optimize(
            [
                $this->address(40.0, -74.0, 'First'),
                $this->address(41.0, -75.0, 'Second'),
            ],
            routes: 1,
            options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 0)
        );

        self::assertNotEmpty($routes->routes);
        self::assertSame('First', $routes->routes[0]->stops[0]->address->label, 'first address must be first in the route');
    }

    public function testAcceptsValidDepotIndexWithClosedLoop(): void
    {
        $service = $this->service(50);

        $routes = $service->optimize(
            [
                $this->address(40.0, -74.0, 'A'),
                $this->address(41.0, -75.0, 'B'),
                $this->address(42.0, -76.0, 'C'),
            ],
            routes: 1,
            options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 2, returnToStart: true)
        );

        self::assertNotEmpty($routes->routes);
        self::assertSame('C', $routes->routes[0]->stops[0]->address->label, 'depot must be first');
        // For a closed loop, the last stop should be the same as the first (returning to depot)
        $lastStop = $routes->routes[0]->stops[count($routes->routes[0]->stops) - 1];
        self::assertSame('C', $lastStop->address->label, 'must return to depot at the end');
    }

    public function testValidDepotIndexWithManyAddresses(): void
    {
        $service = $this->service(50);

        $addresses = [];
        for ($i = 0; $i < 5; $i++) {
            $addresses[] = $this->address(40.0 + $i, -74.0 - $i, "Stop$i");
        }

        $routes = $service->optimize($addresses, routes: 1, options: new \Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions(depotIndex: 4));

        self::assertNotEmpty($routes->routes);
        self::assertSame('Stop4', $routes->routes[0]->stops[0]->address->label, 'last address must be depot and first in route');
    }
}
