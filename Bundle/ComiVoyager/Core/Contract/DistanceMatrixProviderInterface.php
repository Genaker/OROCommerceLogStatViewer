<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Contract;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;

/**
 * Computes a pairwise distance matrix for a set of coordinates. Implementations
 * may be pure (e.g. haversine) or call out to an external service.
 */
interface DistanceMatrixProviderInterface
{
    /**
     * @param Coordinate[] $coordinates
     */
    public function build(array $coordinates): DistanceMatrix;

    /**
     * Short identifier used to select this provider (e.g. "haversine", "vincenty").
     */
    public function getName(): string;
}
