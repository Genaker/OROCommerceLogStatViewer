<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Provider;

use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;

/**
 * Supplies ready-to-ship delivery orders (with resolved coordinates and
 * weights) for the VRP solver, sourced from OroCommerce.
 */
interface DeliveryOrderProviderInterface
{
    /**
     * @return DeliveryOrder[] Orders matching the criteria that have a
     *         geocodable shipping address. Orders without a usable address
     *         are skipped.
     */
    public function getDeliveryOrders(OrderQueryCriteria $criteria): array;
}
