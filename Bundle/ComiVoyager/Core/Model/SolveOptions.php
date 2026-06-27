<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * Options controlling how a route is built.
 */
final class SolveOptions
{
    public function __construct(
        public readonly bool $returnToStart = false,
        public readonly ?int $depotIndex = null,
    ) {
    }
}
