<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Clustering;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPRoute;

/**
 * Post-processes K-Means clusters to enforce hard constraints:
 *  - Vehicle weight capacity
 *  - Max stops per vehicle
 *  - Delivery radius from depot
 *
 * Overflowing orders are moved to the nearest cluster that has room,
 * or marked as unassigned if no cluster can accept them.
 */
final class CapacityAdjuster
{
    /**
     * @param array<int, DeliveryOrder[]> $clusters from KMeansClusterer
     * @param Vehicle[] $vehicles one per cluster
     * @param Coordinate|null $depot for radius check
     * @param float $maxRadiusMiles 0 = no limit
     * @return array{routes: VRPRoute[], unassigned: DeliveryOrder[], out_of_range: DeliveryOrder[]}
     */
    public function adjust(
        array $clusters,
        array $vehicles,
        ?Coordinate $depot = null,
        float $maxRadiusMiles = 0.0
    ): array {
        $outOfRange = [];
        $vehicles = array_values($vehicles);

        // Phase 1: filter out-of-range orders
        if ($depot !== null && $maxRadiusMiles > 0) {
            foreach ($clusters as $ci => $orders) {
                $kept = [];
                foreach ($orders as $order) {
                    if ($this->haversineDistance($depot, $order->getCoordinate()) > $maxRadiusMiles) {
                        $outOfRange[] = $order;
                    } else {
                        $kept[] = $order;
                    }
                }
                $clusters[$ci] = $kept;
            }
        }

        // Phase 2: build routes and collect overflow
        $routes = [];
        $overflow = [];

        foreach ($clusters as $ci => $orders) {
            $vehicle = $vehicles[$ci] ?? $vehicles[0];
            $route = new VRPRoute($vehicle);

            // Sort by priority: urgent first, then high, then normal
            usort($orders, fn(DeliveryOrder $a, DeliveryOrder $b) =>
                $this->priorityScore($b) <=> $this->priorityScore($a)
            );

            foreach ($orders as $order) {
                if ($route->canFit($order)) {
                    $route->addStop($order);
                } else {
                    $overflow[] = $order;
                }
            }

            $routes[$ci] = $route;
        }

        // Phase 3: redistribute overflow to nearest route with capacity
        $unassigned = [];
        foreach ($overflow as $order) {
            $placed = false;
            $routesByDistance = $this->sortRoutesByDistanceTo($routes, $order->getCoordinate());

            foreach ($routesByDistance as $ri) {
                if ($routes[$ri]->canFit($order)) {
                    $routes[$ri]->addStop($order);
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $unassigned[] = $order;
            }
        }

        return [
            'routes'       => array_values($routes),
            'unassigned'   => $unassigned,
            'out_of_range' => $outOfRange,
        ];
    }

    /**
     * @param VRPRoute[] $routes
     * @return int[] route indices sorted by distance to $coord
     */
    private function sortRoutesByDistanceTo(array $routes, Coordinate $coord): array
    {
        $distances = [];
        foreach ($routes as $ri => $route) {
            $centroid = $this->routeCentroid($route);
            $distances[$ri] = $centroid !== null ? $this->haversineDistance($centroid, $coord) : PHP_FLOAT_MAX;
        }
        asort($distances);
        return array_keys($distances);
    }

    private function routeCentroid(VRPRoute $route): ?Coordinate
    {
        $stops = $route->getStops();
        if (empty($stops)) {
            return $route->getVehicle()->getStartLocation();
        }
        $lat = 0.0;
        $lng = 0.0;
        foreach ($stops as $s) {
            $lat += $s->getCoordinate()->lat;
            $lng += $s->getCoordinate()->lng;
        }
        $n = count($stops);
        return new Coordinate($lat / $n, $lng / $n);
    }

    private function priorityScore(DeliveryOrder $order): int
    {
        return match ($order->getPriority()) {
            'urgent' => 3,
            'high'   => 2,
            default  => 1,
        };
    }

    private function haversineDistance(Coordinate $a, Coordinate $b): float
    {
        $earthRadiusMiles = 3958.8;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $sinLat = sin($dLat / 2);
        $sinLng = sin($dLng / 2);
        $h = $sinLat * $sinLat + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * $sinLng * $sinLng;
        return 2 * $earthRadiusMiles * asin(sqrt($h));
    }
}
