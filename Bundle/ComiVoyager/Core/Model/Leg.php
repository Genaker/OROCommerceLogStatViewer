<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * The travel segment between two consecutive stops in a route.
 */
final class Leg
{
    public function __construct(
        public readonly int $fromIndex,
        public readonly int $toIndex,
        public readonly float $distanceKm,
        public readonly float $cumulativeDistanceKm,
    ) {
    }

    /**
     * @return array{fromIndex: int, toIndex: int, distanceKm: float, cumulativeDistanceKm: float}
     */
    public function toArray(): array
    {
        return [
            'fromIndex' => $this->fromIndex,
            'toIndex' => $this->toIndex,
            'distanceKm' => $this->distanceKm,
            'cumulativeDistanceKm' => $this->cumulativeDistanceKm,
        ];
    }
}
