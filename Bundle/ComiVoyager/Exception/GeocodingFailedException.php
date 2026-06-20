<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Exception;

/**
 * Thrown when an address cannot be resolved to a coordinate, or an unknown
 * geocoder is requested.
 */
final class GeocodingFailedException extends \RuntimeException
{
}
