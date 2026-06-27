<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Provider;

/**
 * Filters for selecting which OroCommerce orders to route.
 */
final class OrderQueryCriteria
{
    /**
     * @param string[] $statuses Internal-status codes to include, e.g.
     *        ['order_internal_status.open', 'processing']. Both the full
     *        enum id and the short internal id are matched. Empty = any status.
     * @param int $limit Max orders to fetch.
     * @param \DateTimeInterface|null $createdAfter Only orders created on/after this.
     */
    public function __construct(
        public readonly array $statuses = [],
        public readonly int $limit = 500,
        public readonly ?\DateTimeInterface $createdAfter = null,
    ) {
    }
}
