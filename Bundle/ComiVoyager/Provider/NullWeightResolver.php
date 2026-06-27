<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Provider;

use Oro\Bundle\OrderBundle\Entity\Order;

/**
 * Default weight resolver: reports 0 lbs for every order, which the VRP
 * solver treats as "no weight constraint". Projects that track shipping
 * weight should provide their own {@see OrderWeightResolverInterface}.
 */
final class NullWeightResolver implements OrderWeightResolverInterface
{
    public function resolveWeightLbs(Order $order): float
    {
        return 0.0;
    }
}
