<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Clustering\CapacityAdjuster;
use Genaker\Bundle\ComiVoyager\Core\Clustering\KMeansClusterer;
use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Contract\VRPSolverInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\RouteStop;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPRoute;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPSolution;

/**
 * Vehicle Routing Problem solver.
 *
 * Pipeline:
 *  1. Cluster orders into K groups (K-Means on coordinates)
 *  2. Adjust clusters for weight capacity / max stops / delivery radius
 *  3. Route each cluster with the vehicle's own start, end and round-trip
 *     setting (RouteSequencer)
 *  4. Trim each route to fit the driver's distance and time (shift) budget,
 *     pushing dropped stops to "unassigned"
 */
final class VRPSolver implements VRPSolverInterface
{
    private const KM_TO_MILES = 0.621371;

    public function __construct(
        private readonly DistanceMatrixProviderInterface $distanceProvider,
        private readonly KMeansClusterer $clusterer = new KMeansClusterer(),
        private readonly CapacityAdjuster $adjuster = new CapacityAdjuster(),
        private ?RouteSequencer $sequencer = null,
    ) {
        $this->sequencer ??= new RouteSequencer($distanceProvider);
    }

    public function getName(): string
    {
        return 'local';
    }

    /**
     * @param DeliveryOrder[] $orders
     * @param Vehicle[] $vehicles
     * @param Coordinate $depot Shared warehouse / fallback start when a
     *        vehicle has no explicit start location.
     */
    public function solve(
        array $orders,
        array $vehicles,
        Coordinate $depot,
        float $maxRadiusMiles = 100.0,
    ): VRPSolution {
        if (empty($orders) || empty($vehicles)) {
            return new VRPSolution();
        }

        $clusters = $this->clusterer->cluster($orders, count($vehicles), $depot);
        $adjusted = $this->adjuster->adjust($clusters, $vehicles, $depot, $maxRadiusMiles);

        /** @var VRPRoute[] $routes */
        $routes = $adjusted['routes'];
        $unassigned = $adjusted['unassigned'];

        foreach ($routes as $route) {
            $this->routeAndBudget($route, $depot, $unassigned);
        }

        return new VRPSolution($routes, $unassigned, $adjusted['out_of_range']);
    }

    /**
     * Sequence the route's stops, then enforce the vehicle's distance and
     * time budgets by dropping the farthest tail stops into $unassigned.
     *
     * @param DeliveryOrder[] $unassigned passed by reference
     */
    private function routeAndBudget(VRPRoute $route, Coordinate $depot, array &$unassigned): void
    {
        if ($route->getStopCount() === 0) {
            return;
        }

        $vehicle = $route->getVehicle();
        $start = $vehicle->resolveStart($depot);
        $end = $vehicle->resolveEnd($depot);

        $this->sequenceRoute($route, $start, $end);

        // Trim until distance and time budgets are satisfied. Each iteration
        // removes the last stop in the optimized order (the farthest point of
        // the day) and re-sequences the remainder.
        while ($route->getStopCount() > 0 && $this->overBudget($route)) {
            $dropped = $route->removeStop($route->getStopCount() - 1);
            if ($dropped !== null) {
                $unassigned[] = $dropped;
            }
            if ($route->getStopCount() > 0) {
                $this->sequenceRoute($route, $start, $end);
            } else {
                $route->setTotalDistanceMiles(0.0);
            }
        }
    }

    private function sequenceRoute(VRPRoute $route, Coordinate $start, ?Coordinate $end): void
    {
        $vehicle = $route->getVehicle();
        $stops = $route->getStops();
        $coords = array_map(static fn (DeliveryOrder $o) => $o->getCoordinate(), $stops);

        $result = $this->sequencer->sequence(
            $coords,
            $start,
            $end,
            $vehicle->shouldReturnToStart(),
        );

        $reordered = [];
        foreach ($result['order'] as $idx) {
            $reordered[] = $stops[$idx];
        }

        $route->setStops($reordered);
        $route->setTotalDistanceMiles($result['distanceKm'] * self::KM_TO_MILES);
        $route->setFinalLegMiles($result['finalLegKm'] * self::KM_TO_MILES);

        // Build per-stop ETA detail from the per-leg distances. The shift
        // clock starts at 0 hours when the driver leaves the start location.
        $speed = $vehicle->getAvgSpeedMph();
        $serviceHours = $vehicle->getServiceTimeMinutes() / 60.0;

        $details = [];
        $clock = 0.0;
        $cumulativeMiles = 0.0;
        foreach ($result['order'] as $seq => $idx) {
            $legMiles = $result['legsKm'][$seq] * self::KM_TO_MILES;
            $cumulativeMiles += $legMiles;

            $arrival = $clock + $legMiles / $speed;
            $departure = $arrival + $serviceHours;
            $clock = $departure;

            $details[] = new RouteStop(
                $seq + 1,
                $reordered[$seq],
                $legMiles,
                $cumulativeMiles,
                $arrival,
                $departure,
            );
        }

        $route->setStopDetails($details);
    }

    private function overBudget(VRPRoute $route): bool
    {
        $vehicle = $route->getVehicle();

        if ($vehicle->hasDistanceLimit() &&
            $route->getTotalDistanceMiles() > $vehicle->getMaxDistanceMiles()) {
            return true;
        }

        if ($vehicle->hasTimeLimit() &&
            $route->getTotalDurationHours() > $vehicle->getMaxWorkHours()) {
            return true;
        }

        return false;
    }
}
