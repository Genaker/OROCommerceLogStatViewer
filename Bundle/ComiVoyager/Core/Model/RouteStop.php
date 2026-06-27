<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * One delivery stop within a VRP route, enriched with the per-leg distance
 * from the previous point and the driver's estimated arrival/departure time.
 *
 * Times are expressed as hours-into-the-shift (0.0 = the moment the driver
 * leaves the start location). Multiply by 60 for minutes, or add to a shift
 * start clock time to get a wall-clock ETA.
 */
final class RouteStop
{
    public function __construct(
        public readonly int $sequence,
        public readonly DeliveryOrder $order,
        public readonly float $legDistanceMiles,
        public readonly float $cumulativeDistanceMiles,
        public readonly float $arrivalHours,
        public readonly float $departureHours,
    ) {
    }

    /**
     * @return array{
     *     sequence: int,
     *     order_id: string,
     *     lat: float,
     *     lng: float,
     *     weight_lbs: float,
     *     leg_distance_miles: float,
     *     cumulative_distance_miles: float,
     *     arrival_hours: float,
     *     departure_hours: float,
     *     eta_minutes: float
     * }
     */
    public function toArray(): array
    {
        return [
            'sequence'                  => $this->sequence,
            'order_id'                  => $this->order->getId(),
            'address'                   => $this->order->getLabel(),
            'lat'                       => $this->order->getCoordinate()->lat,
            'lng'                       => $this->order->getCoordinate()->lng,
            'weight_lbs'                => round($this->order->getWeightLbs(), 1),
            'leg_distance_miles'        => round($this->legDistanceMiles, 2),
            'cumulative_distance_miles' => round($this->cumulativeDistanceMiles, 2),
            'arrival_hours'             => round($this->arrivalHours, 3),
            'departure_hours'           => round($this->departureHours, 3),
            'eta_minutes'               => round($this->arrivalHours * 60, 1),
        ];
    }
}
