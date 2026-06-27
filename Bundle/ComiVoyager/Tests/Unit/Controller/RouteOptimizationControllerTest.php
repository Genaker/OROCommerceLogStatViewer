<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\ComiVoyager\Controller\RouteOptimizationController;
use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Distance\DistanceProviderRegistry;
use Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry;
use Genaker\Bundle\ComiVoyager\Service\RouteOptimizationService;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Controller\RouteOptimizationController
 *
 * RouteOptimizationService is final and cannot be mocked, so these tests
 * build a real instance (with mocked ConfigManager/registries, as in
 * RouteOptimizationServiceTest) and exercise the controller's exception ->
 * HTTP status mapping end-to-end.
 */
final class RouteOptimizationControllerTest extends TestCase
{
    private function controller(?int $maxAddresses = 50): RouteOptimizationController
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

        $service = new RouteOptimizationService($distanceProviderRegistry, $geocoderRegistry, $configManager);

        $controller = new RouteOptimizationController($service);
        $controller->setContainer(new Container());

        return $controller;
    }

    private function requestWithBody(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function address(float|string $lat, float|string $lng, string $label = 'Stop'): array
    {
        return ['label' => $label, 'lat' => $lat, 'lng' => $lng];
    }

    public function testReturnsBadRequestForInvalidJson(): void
    {
        $controller = $this->controller();

        $request = new Request([], [], [], [], [], [], '{not json');
        $response = $controller->optimizeAction($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('Invalid JSON', (string) $response->getContent());
    }

    public function testReturnsBadRequestWhenAddressesFieldMissing(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody(['method' => 'haversine']));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Field "addresses" is required and must be an array.'],
            json_decode((string) $response->getContent(), true)
        );
    }

    /**
     * Covers the new `max_addresses` enforcement: a request with more
     * addresses than the configured limit is rejected with HTTP 400.
     */
    public function testReturnsBadRequestWhenTooManyAddresses(): void
    {
        $controller = $this->controller(maxAddresses: 2);

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                $this->address(40.0, -74.0),
                $this->address(41.0, -75.0),
                $this->address(42.0, -76.0),
            ],
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Too many addresses: 3 given, maximum is 2.'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testAllowsAddressCountAtConfiguredMaxAddresses(): void
    {
        $controller = $this->controller(maxAddresses: 2);

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                $this->address(40.0, -74.0),
                $this->address(41.0, -75.0),
            ],
            'routes' => 1,
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Covers the new non-numeric lat/lng validation: a typo like "abc"
     * is rejected with HTTP 400 instead of silently becoming 0.0.
     */
    public function testReturnsBadRequestForNonNumericCoordinates(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                $this->address('abc', -74.0, 'A'),
                $this->address(41.0, -75.0, 'B'),
            ],
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Address at position 0 has non-numeric "lat"/"lng".'],
            json_decode((string) $response->getContent(), true)
        );
    }

    /**
     * Covers out-of-range lat/lng, which surfaces as an InvalidArgumentException
     * from Coordinate's constructor and is mapped the same way (HTTP 400).
     */
    public function testReturnsBadRequestForOutOfRangeCoordinates(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                $this->address(999.0, -74.0, 'A'),
                $this->address(41.0, -75.0, 'B'),
            ],
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturnsBadRequestForInsufficientAddresses(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [$this->address(40.0, -74.0)],
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('At least 2 addresses are required', $payload['error']);
    }

    public function testReturnsUnprocessableEntityWhenGeocodingFails(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                ['label' => 'A', 'address' => 'somewhere unresolvable'],
                $this->address(41.0, -75.0, 'B'),
            ],
        ]));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Unknown geocoder', $payload['error']);
    }

    public function testReturnsUnprocessableEntityWhenDistanceProviderUnavailable(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                $this->address(40.0, -74.0, 'A'),
                $this->address(41.0, -75.0, 'B'),
            ],
            'method' => 'postgis',
        ]));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Unknown distance provider "postgis"', $payload['error']);
    }

    public function testReturnsOptimizedRouteCollectionOnSuccess(): void
    {
        $controller = $this->controller();

        $response = $controller->optimizeAction($this->requestWithBody([
            'addresses' => [
                $this->address(40.7128, -74.0060, 'New York'),
                $this->address(34.0522, -118.2437, 'Los Angeles'),
            ],
            'routes' => 1,
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertNotEmpty($payload['routes']);
        self::assertSame(0, $payload['shortestIndex']);
    }
}
