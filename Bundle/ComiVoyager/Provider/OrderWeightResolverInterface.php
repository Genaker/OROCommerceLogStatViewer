<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Provider;

use Oro\Bundle\OrderBundle\Entity\Order;

/**
 * Resolves the shipping weight (lbs) of an order for capacity planning.
 *
 * Weight in OroCommerce is project-specific — it can come from product
 * shipping attributes, a custom order field, or an external system (e.g.
 * SAP). The bundle ships {@see NullWeightResolver} (returns 0 = unlimited
 * capacity); projects override this to plug in their own weight source.
 */
interface OrderWeightResolverInterface
{
    public function resolveWeightLbs(Order $order): float;
}
