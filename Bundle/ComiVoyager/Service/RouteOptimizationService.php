<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Service;

use Genaker\Bundle\ComiVoyager\Core\ComiVoyager;
use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\RouteCollection;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\TopNRouteSolver;
use Genaker\Bundle\ComiVoyager\Distance\DistanceProviderRegistry;
use Genaker\Bundle\ComiVoyager\Exception\GeocodingFailedException;
use Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Orchestrates a route-optimization request: resolves any free-text
 * addresses to coordinates via {@see GeocoderRegistry}, then delegates to
 * the framework-agnostic {@see ComiVoyager} facade using the configured (or
 * explicitly requested) distance provider.
 */
final class RouteOptimizationService
{
    private const DEFAULT_ROUTE_COUNT = 3;

    /**
     * 9 keeps every request inside the exhaustive solver's measured budget
     * (~3.4s/425MB at n=9; n=10 took ~7min/3.9GB before it was excluded from
     * the exact path). Raising this is safe performance-wise — n >= 10 uses
     * Held-Karp/heuristics, which are fast — but the ranked runners-up are
     * then no longer guaranteed to be the true 2nd/3rd-best routes.
     */
    private const DEFAULT_MAX_ADDRESSES = 9;

    public function __construct(
        private readonly DistanceProviderRegistry $distanceProviderRegistry,
        private readonly GeocoderRegistry $geocoderRegistry,
        private readonly ConfigManager $configManager,
        private readonly TopNRouteSolver $solver = new TopNRouteSolver(),
    ) {
    }

    /**
     * @param array<int, array{label?: string, lat?: float|string, lng?: float|string, address?: string}> $rawAddresses
     */
    public function optimize(
        array $rawAddresses,
        ?string $distanceProvider = null,
        ?string $geocoder = null,
        ?int $routes = null,
        ?SolveOptions $options = null,
    ): RouteCollection {
        $maxAddresses = (int) $this->configManager->get('genaker_comi_voyager.max_addresses')
            ?: self::DEFAULT_MAX_ADDRESSES;

        if (count($rawAddresses) > $maxAddresses) {
            throw new \InvalidArgumentException(sprintf(
                'Too many addresses: %d given, maximum is %d.',
                count($rawAddresses),
                $maxAddresses
            ));
        }

        $addresses = $this->resolveAddresses($rawAddresses, $geocoder);

        $options ??= new SolveOptions();

        if ($options->depotIndex !== null) {
            $addressCount = count($addresses);
            if ($options->depotIndex < 0 || $options->depotIndex >= $addressCount) {
                throw new \InvalidArgumentException(sprintf(
                    'Depot index %d is out of range [0, %d).',
                    $options->depotIndex,
                    $addressCount
                ));
            }
        }

        $provider = $this->distanceProviderRegistry->get($distanceProvider);
        $defaultRouteCount = (int) $this->configManager->get('genaker_comi_voyager.default_route_count')
            ?: self::DEFAULT_ROUTE_COUNT;

        $engine = new ComiVoyager($provider, $this->solver, $defaultRouteCount);

        return $engine->optimize($addresses, $routes, $options);
    }

    /**
     * @param array<int, array{label?: string, lat?: float|string, lng?: float|string, address?: string}> $rawAddresses
     * @return Address[]
     */
    private function resolveAddresses(array $rawAddresses, ?string $geocoder): array
    {
        $addresses = [];

        foreach ($rawAddresses as $position => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException(sprintf('Address at position %d must be an object.', $position));
            }

            $label = isset($entry['label']) ? (string) $entry['label'] : sprintf('Address %d', $position + 1);

            if (isset($entry['lat'], $entry['lng'])) {
                if (!is_numeric($entry['lat']) || !is_numeric($entry['lng'])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Address at position %d has non-numeric "lat"/"lng".',
                        $position
                    ));
                }

                $addresses[] = new Address($label, new Coordinate((float) $entry['lat'], (float) $entry['lng']));

                continue;
            }

            if (empty($entry['address'])) {
                throw new \InvalidArgumentException(sprintf(
                    'Address at position %d must have either "lat"/"lng" or a text "address".',
                    $position
                ));
            }

            $coordinate = $this->geocoderRegistry->get($geocoder)->geocode((string) $entry['address']);
            if ($coordinate === null) {
                throw new GeocodingFailedException(sprintf(
                    'Could not geocode address at position %d: "%s".',
                    $position,
                    $entry['address']
                ));
            }

            $addresses[] = new Address($label, $coordinate);
        }

        return $addresses;
    }
}
