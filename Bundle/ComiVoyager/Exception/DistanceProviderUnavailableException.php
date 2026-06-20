<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Exception;

/**
 * Thrown when an unknown distance provider is requested, or a provider
 * cannot service the given request (e.g. too many points for a single
 * Google Distance Matrix call).
 */
final class DistanceProviderUnavailableException extends \RuntimeException
{
}
