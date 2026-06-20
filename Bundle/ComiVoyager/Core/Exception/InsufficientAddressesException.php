<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Exception;

/**
 * Thrown when fewer than two addresses are supplied to the solver.
 */
final class InsufficientAddressesException extends \InvalidArgumentException
{
}
