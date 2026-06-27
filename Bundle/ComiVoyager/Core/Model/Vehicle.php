<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * A delivery driver + vehicle unit.
 *
 * Captures everything the VRP solver needs about one "delivery guy":
 *  - where they start (home / warehouse) and end their shift
 *  - load capacity and how many stops they can handle
 *  - how long their working day is and how fast they drive,
 *    so the solver can budget time, not just distance
 */
final class Vehicle
{
    /**
     * @param int $id Stable identifier for the driver/vehicle.
     * @param float $capacityLbs Max load weight; 0 = no weight limit.
     * @param int $maxStops Max deliveries per shift; 0 = no stop limit.
     * @param float $maxDistanceMiles Max driving distance per shift; 0 = no limit.
     * @param Coordinate|null $startLocation Driver's start point (home/warehouse);
     *        null falls back to the shared depot passed to VRPSolver::solve().
     * @param Coordinate|null $endLocation Where the driver finishes. null + returnToStart=false
     *        means "end at the last delivery" (open route).
     * @param bool $returnToStart If true, the route is a round trip back to startLocation.
     * @param float $avgSpeedMph Average driving speed, used to convert miles → hours.
     * @param float $serviceTimeMinutes Time spent at each stop (unloading), in minutes.
     * @param float $maxWorkHours Length of the working day; 0 = no time limit.
     */
    public function __construct(
        private readonly int $id,
        private readonly float $capacityLbs = 0.0,
        private readonly int $maxStops = 0,
        private readonly float $maxDistanceMiles = 0.0,
        private readonly ?Coordinate $startLocation = null,
        private readonly ?Coordinate $endLocation = null,
        private readonly bool $returnToStart = true,
        private readonly float $avgSpeedMph = 30.0,
        private readonly float $serviceTimeMinutes = 10.0,
        private readonly float $maxWorkHours = 0.0,
    ) {
    }

    public function getId(): int { return $this->id; }
    public function getCapacityLbs(): float { return $this->capacityLbs; }
    public function getMaxStops(): int { return $this->maxStops; }
    public function getMaxDistanceMiles(): float { return $this->maxDistanceMiles; }
    public function getStartLocation(): ?Coordinate { return $this->startLocation; }
    public function getEndLocation(): ?Coordinate { return $this->endLocation; }
    public function shouldReturnToStart(): bool { return $this->returnToStart; }
    public function getAvgSpeedMph(): float { return max(1.0, $this->avgSpeedMph); }
    public function getServiceTimeMinutes(): float { return max(0.0, $this->serviceTimeMinutes); }
    public function getMaxWorkHours(): float { return $this->maxWorkHours; }

    public function hasCapacityLimit(): bool { return $this->capacityLbs > 0; }
    public function hasStopLimit(): bool { return $this->maxStops > 0; }
    public function hasDistanceLimit(): bool { return $this->maxDistanceMiles > 0; }
    public function hasTimeLimit(): bool { return $this->maxWorkHours > 0; }

    /**
     * Resolve where this driver starts: their own start location, or the
     * shared depot when none is set.
     */
    public function resolveStart(Coordinate $depot): Coordinate
    {
        return $this->startLocation ?? $depot;
    }

    /**
     * Resolve the driver's end point:
     *  - explicit endLocation if set
     *  - else startLocation/depot if returnToStart
     *  - else null (open route — finish at the last delivery)
     */
    public function resolveEnd(Coordinate $depot): ?Coordinate
    {
        if ($this->endLocation !== null) {
            return $this->endLocation;
        }
        if ($this->returnToStart) {
            return $this->resolveStart($depot);
        }
        return null;
    }

    /**
     * Convert a driving distance + number of stops into total shift hours.
     */
    public function estimateHours(float $distanceMiles, int $stopCount): float
    {
        $drivingHours = $distanceMiles / $this->getAvgSpeedMph();
        $serviceHours = $stopCount * $this->getServiceTimeMinutes() / 60.0;
        return $drivingHours + $serviceHours;
    }
}
