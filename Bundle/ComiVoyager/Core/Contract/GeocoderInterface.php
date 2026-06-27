<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Contract;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;

/**
 * Resolves a free-text address into a coordinate. Implementations may call
 * out to an external geocoding service.
 */
interface GeocoderInterface
{
    /**
     * Returns the resolved coordinate, or null if the address could not be
     * geocoded.
     */
    public function geocode(string $address): ?Coordinate;

    /**
     * Short identifier used to select this geocoder (e.g. "nominatim", "google").
     */
    public function getName(): string;
}
