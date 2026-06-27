<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * A WGS-84 latitude/longitude pair.
 */
final class Coordinate
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new \InvalidArgumentException(sprintf('Latitude %.6f is out of range [-90, 90].', $lat));
        }

        if ($lng < -180.0 || $lng > 180.0) {
            throw new \InvalidArgumentException(sprintf('Longitude %.6f is out of range [-180, 180].', $lng));
        }
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
