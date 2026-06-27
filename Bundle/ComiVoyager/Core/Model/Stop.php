<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * A single stop within a route, including the leg traveled to reach it.
 */
final class Stop
{
    public function __construct(
        public readonly int $sequence,
        public readonly Address $address,
        public readonly ?Leg $legFromPrevious,
        public readonly bool $isStart,
        public readonly bool $isEnd,
    ) {
    }

    /**
     * @return array{
     *     sequence: int,
     *     addressLabel: string,
     *     coordinate: array{lat: float, lng: float},
     *     legDistanceKm: ?float,
     *     cumulativeDistanceKm: float,
     *     isStart: bool,
     *     isEnd: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'addressLabel' => $this->address->label,
            'coordinate' => $this->address->coordinate->toArray(),
            'legDistanceKm' => $this->legFromPrevious?->distanceKm,
            'cumulativeDistanceKm' => $this->legFromPrevious !== null ? $this->legFromPrevious->cumulativeDistanceKm : 0.0,
            'isStart' => $this->isStart,
            'isEnd' => $this->isEnd,
        ];
    }
}
