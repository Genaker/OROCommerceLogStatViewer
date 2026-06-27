<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Exception\InsufficientAddressesException;
use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\RouteCollection;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Solver\TopNRouteSolver;

/**
 * General-purpose entry point: given a set of addresses with known
 * coordinates, returns the top-N most efficient visiting orders.
 *
 * This class has no dependency on Symfony or Oro — it can be used standalone
 * with any DistanceMatrixProviderInterface implementation.
 *
 * High-level flow of {@see self::optimize()}:
 *   1. Validate there are enough addresses to form a route.
 *   2. Extract coordinates and ask the configured
 *      {@see DistanceMatrixProviderInterface} (haversine, vincenty, osrm,
 *      google, or postgis — see ../../doc/distance-algorithms/) to build an
 *      N x N distance matrix.
 *   3. Hand the matrix to {@see TopNRouteSolver}, which runs the TSP search
 *      strategies (see ../../doc/ALGORITHMS.md) and returns the top-N
 *      ranked routes.
 *
 * Distance computation and route search are deliberately decoupled: any
 * distance provider can be paired with the solver without either side
 * knowing about the other.
 */
final class ComiVoyager
{
    /**
     * A "route" requires at least a start and an end — fewer than 2
     * addresses can't form any ordering, so optimize() rejects the input
     * before doing any work.
     */
    private const MIN_ADDRESSES = 2;

    public function __construct(
        private readonly DistanceMatrixProviderInterface $distanceProvider,
        private readonly TopNRouteSolver $solver = new TopNRouteSolver(),
        private readonly int $defaultRouteCount = 3,
    ) {
    }

    /**
     * @param Address[] $addresses Stops to visit, each with a resolved
     *        {@see \Genaker\Bundle\ComiVoyager\Core\Model\Coordinate}.
     * @param int|null $routes How many ranked alternative routes to return;
     *        defaults to {@see self::$defaultRouteCount} (3) when null.
     * @param SolveOptions|null $options Controls fixed depot/start and
     *        whether the route must return to its starting point; defaults
     *        to a free-start, one-way route when null.
     */
    public function optimize(array $addresses, ?int $routes = null, ?SolveOptions $options = null): RouteCollection
    {
        if (count($addresses) < self::MIN_ADDRESSES) {
            throw new InsufficientAddressesException(sprintf(
                'At least %d addresses are required, %d given.',
                self::MIN_ADDRESSES,
                count($addresses),
            ));
        }

        // Reduce each Address down to just its Coordinate — the distance
        // provider only needs lat/lng, not the full address payload (label,
        // free-text address, etc.).
        $coordinates = array_map(static fn (Address $address) => $address->coordinate, $addresses);

        // Build the N x N distance matrix once, up front. Every search
        // strategy inside TopNRouteSolver looks up distances via this same
        // matrix (DistanceMatrix::distanceBetween()) rather than calling
        // the provider again — this is what makes the solver's O(N^2) and
        // larger lookups cheap regardless of which provider is used.
        $matrix = $this->distanceProvider->build($coordinates);

        return $this->solver->solve($addresses, $matrix, $routes ?? $this->defaultRouteCount, $options ?? new SolveOptions());
    }
}
