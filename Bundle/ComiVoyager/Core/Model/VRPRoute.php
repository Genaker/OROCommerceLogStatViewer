<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

final class VRPRoute
{
    /** @var DeliveryOrder[] */
    private array $stops;
    private float $totalDistanceMiles = 0.0;
    private float $totalWeightLbs = 0.0;
    private float $totalDurationHours = 0.0;

    /** @var Leg[] */
    private array $legs = [];

    /** @var RouteStop[] per-stop leg distance + ETA detail */
    private array $stopDetails = [];

    /** Distance of the final return/end leg (last stop → end point), in miles. */
    private float $finalLegMiles = 0.0;

    /**
     * @param DeliveryOrder[] $stops in optimized visiting order
     */
    public function __construct(
        private readonly Vehicle $vehicle,
        array $stops = [],
    ) {
        $this->stops = $stops;
        $this->recalculateWeight();
    }

    public function getVehicle(): Vehicle { return $this->vehicle; }

    /** @return DeliveryOrder[] */
    public function getStops(): array { return $this->stops; }

    public function getStopCount(): int { return count($this->stops); }

    public function getTotalDistanceMiles(): float { return $this->totalDistanceMiles; }

    public function getTotalWeightLbs(): float { return $this->totalWeightLbs; }

    public function getRemainingCapacityLbs(): float
    {
        if (!$this->vehicle->hasCapacityLimit()) {
            return PHP_FLOAT_MAX;
        }
        return max(0, $this->vehicle->getCapacityLbs() - $this->totalWeightLbs);
    }

    public function canFit(DeliveryOrder $order): bool
    {
        if ($this->vehicle->hasCapacityLimit() &&
            $this->totalWeightLbs + $order->getWeightLbs() > $this->vehicle->getCapacityLbs()) {
            return false;
        }
        if ($this->vehicle->hasStopLimit() && count($this->stops) >= $this->vehicle->getMaxStops()) {
            return false;
        }
        return true;
    }

    public function addStop(DeliveryOrder $order): void
    {
        $this->stops[] = $order;
        $this->totalWeightLbs += $order->getWeightLbs();
    }

    public function removeStop(int $index): ?DeliveryOrder
    {
        if (!isset($this->stops[$index])) {
            return null;
        }
        $order = $this->stops[$index];
        array_splice($this->stops, $index, 1);
        $this->totalWeightLbs -= $order->getWeightLbs();
        return $order;
    }

    /** @param DeliveryOrder[] $stops */
    public function setStops(array $stops): void
    {
        $this->stops = $stops;
        $this->recalculateWeight();
        $this->recalculateDuration();
        // Stop details are derived from a specific ordering — invalidate them
        // until the solver recomputes ETAs for the new order.
        $this->stopDetails = [];
    }

    public function setTotalDistanceMiles(float $distance): void
    {
        $this->totalDistanceMiles = $distance;
        $this->recalculateDuration();
    }

    public function getTotalDurationHours(): float
    {
        return $this->totalDurationHours;
    }

    /**
     * Driving time + per-stop service time, derived from the vehicle's
     * average speed and service-time-per-stop settings.
     */
    private function recalculateDuration(): void
    {
        $this->totalDurationHours = $this->vehicle->estimateHours(
            $this->totalDistanceMiles,
            count($this->stops),
        );
    }

    /** @param Leg[] $legs */
    public function setLegs(array $legs): void
    {
        $this->legs = $legs;
        $this->totalDistanceMiles = array_sum(array_map(fn(Leg $l) => $l->getDistance(), $legs));
    }

    /** @return Leg[] */
    public function getLegs(): array { return $this->legs; }

    /** @return string[] */
    public function getStopIds(): array
    {
        return array_map(fn(DeliveryOrder $o) => $o->getId(), $this->stops);
    }

    /** @param RouteStop[] $details */
    public function setStopDetails(array $details): void
    {
        $this->stopDetails = $details;
    }

    /** @return RouteStop[] */
    public function getStopDetails(): array
    {
        return $this->stopDetails;
    }

    public function setFinalLegMiles(float $miles): void
    {
        $this->finalLegMiles = $miles;
    }

    public function getFinalLegMiles(): float
    {
        return $this->finalLegMiles;
    }

    private function recalculateWeight(): void
    {
        $this->totalWeightLbs = array_sum(
            array_map(fn(DeliveryOrder $o) => $o->getWeightLbs(), $this->stops)
        );
    }
}
