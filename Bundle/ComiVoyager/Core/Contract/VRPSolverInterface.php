<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Contract;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPSolution;

interface VRPSolverInterface
{
    /**
     * @param DeliveryOrder[] $orders
     * @param Vehicle[] $vehicles
     */
    public function solve(
        array $orders,
        array $vehicles,
        Coordinate $depot,
        float $maxRadiusMiles = 100.0,
    ): VRPSolution;

    public function getName(): string;
}
