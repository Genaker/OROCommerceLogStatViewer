<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

final class VRPSolution
{
    /** @var VRPRoute[] */
    private array $routes;

    /** @var DeliveryOrder[] orders that couldn't be assigned */
    private array $unassigned;

    /** @var DeliveryOrder[] orders beyond delivery radius */
    private array $outOfRange;

    /**
     * @param VRPRoute[] $routes
     * @param DeliveryOrder[] $unassigned
     * @param DeliveryOrder[] $outOfRange
     */
    public function __construct(array $routes = [], array $unassigned = [], array $outOfRange = [])
    {
        $this->routes = $routes;
        $this->unassigned = $unassigned;
        $this->outOfRange = $outOfRange;
    }

    /** @return VRPRoute[] */
    public function getRoutes(): array { return $this->routes; }

    /** @return DeliveryOrder[] */
    public function getUnassigned(): array { return $this->unassigned; }

    /** @return DeliveryOrder[] */
    public function getOutOfRange(): array { return $this->outOfRange; }

    public function getTotalDistanceMiles(): float
    {
        return array_sum(array_map(fn(VRPRoute $r) => $r->getTotalDistanceMiles(), $this->routes));
    }

    public function getMaxRouteDistanceMiles(): float
    {
        if (empty($this->routes)) {
            return 0.0;
        }
        return max(array_map(fn(VRPRoute $r) => $r->getTotalDistanceMiles(), $this->routes));
    }

    public function getMaxRouteDurationHours(): float
    {
        if (empty($this->routes)) {
            return 0.0;
        }
        return max(array_map(fn(VRPRoute $r) => $r->getTotalDurationHours(), $this->routes));
    }

    public function getVehiclesUsed(): int
    {
        return count(array_filter($this->routes, fn(VRPRoute $r) => $r->getStopCount() > 0));
    }

    public function getTotalOrdersAssigned(): int
    {
        return array_sum(array_map(fn(VRPRoute $r) => $r->getStopCount(), $this->routes));
    }

    public function toArray(): array
    {
        $routes = [];
        foreach ($this->routes as $route) {
            if ($route->getStopCount() === 0) {
                continue;
            }
            $routes[] = [
                'vehicle'               => $route->getVehicle()->getId(),
                'stops'                 => $route->getStopIds(),
                'stop_count'            => $route->getStopCount(),
                'total_distance_miles'  => round($route->getTotalDistanceMiles(), 2),
                'total_weight_lbs'      => round($route->getTotalWeightLbs(), 1),
                'total_duration_hours'  => round($route->getTotalDurationHours(), 2),
                'return_leg_miles'      => round($route->getFinalLegMiles(), 2),
                'stop_details'          => array_map(
                    static fn ($s) => $s->toArray(),
                    $route->getStopDetails(),
                ),
            ];
        }

        return [
            'routes'  => $routes,
            'summary' => [
                'total_distance_miles'    => round($this->getTotalDistanceMiles(), 2),
                'max_route_distance'      => round($this->getMaxRouteDistanceMiles(), 2),
                'max_route_duration_hours' => round($this->getMaxRouteDurationHours(), 2),
                'vehicles_used'           => $this->getVehiclesUsed(),
                'orders_assigned'         => $this->getTotalOrdersAssigned(),
                'orders_unassigned'       => count($this->unassigned),
                'orders_out_of_range'     => count($this->outOfRange),
            ],
        ];
    }
}
