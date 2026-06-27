<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry;
use Oro\Bundle\AddressBundle\Entity\AbstractAddress;
use Oro\Bundle\OrderBundle\Entity\Order;

/**
 * Pulls ready-to-ship orders out of OroCommerce and turns them into
 * {@see DeliveryOrder} value objects the VRP solver understands.
 *
 * For each order it:
 *  1. checks the internal status against the criteria,
 *  2. formats the shipping address into a free-text string,
 *  3. geocodes it (via the configured geocoder, which is DB-cached),
 *  4. resolves the shipping weight (pluggable {@see OrderWeightResolverInterface}).
 *
 * Orders without a shipping address or that fail to geocode are skipped.
 * Uses the Doctrine ORM repository (entity-first) rather than raw SQL.
 */
class OroOrderProvider implements DeliveryOrderProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly GeocoderRegistry $geocoders,
        private readonly OrderWeightResolverInterface $weightResolver,
        private readonly ?string $geocoderName = null,
    ) {
    }

    public function getDeliveryOrders(OrderQueryCriteria $criteria): array
    {
        $orders = $this->fetchOrders($criteria);
        $geocoder = $this->geocoders->get($this->geocoderName);

        $result = [];
        foreach ($orders as $order) {
            if (!$this->matchesStatus($order, $criteria->statuses)) {
                continue;
            }

            $address = $order->getShippingAddress();
            if (!$address instanceof AbstractAddress) {
                continue;
            }
            $addressText = $this->formatAddress($address);
            if ($addressText === '') {
                continue;
            }

            $coordinate = $geocoder->geocode($addressText);
            if ($coordinate === null) {
                continue;
            }

            $result[] = $this->buildDeliveryOrder($order, $coordinate, $addressText);
        }

        return $result;
    }

    /**
     * @return Order[]
     */
    protected function fetchOrders(OrderQueryCriteria $criteria): array
    {
        $repository = $this->doctrine->getRepository(Order::class);

        $qb = $repository->createQueryBuilder('o')
            ->leftJoin('o.shippingAddress', 'a')
            ->addSelect('a')
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($criteria->limit);

        if ($criteria->createdAfter !== null) {
            $qb->andWhere('o.createdAt >= :createdAfter')
                ->setParameter('createdAfter', $criteria->createdAfter);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Build the comma-separated, free-text address string a geocoder expects.
     */
    public function formatAddress(AbstractAddress $address): string
    {
        $parts = [
            $address->getStreet(),
            $address->getStreet2(),
            $address->getCity(),
            $address->getRegionName() ?: $address->getRegionCode(),
            $address->getPostalCode(),
            $address->getCountryName() ?: $address->getCountryIso2(),
        ];

        $parts = array_filter(array_map(
            static fn ($p) => is_string($p) ? trim($p) : '',
            $parts,
        ), static fn (string $p) => $p !== '');

        return implode(', ', $parts);
    }

    /**
     * @param string[] $statuses
     */
    public function matchesStatus(Order $order, array $statuses): bool
    {
        if (empty($statuses)) {
            return true;
        }

        $status = $order->getInternalStatus();
        if ($status === null) {
            return false;
        }

        // Match against both the full enum id (order_internal_status.open)
        // and the short internal id (open).
        $candidates = array_filter([
            method_exists($status, 'getId') ? $status->getId() : null,
            method_exists($status, 'getInternalId') ? $status->getInternalId() : null,
        ]);

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    private function buildDeliveryOrder(Order $order, Coordinate $coordinate, string $addressText): DeliveryOrder
    {
        return new DeliveryOrder(
            (string) $order->getIdentifier(),
            $coordinate,
            $this->weightResolver->resolveWeightLbs($order),
            'normal',
            $this->resolveCustomerId($order),
            $addressText,
        );
    }

    /**
     * Customer is an extend relation on Order; resolve it defensively so the
     * provider works regardless of how the project configures customer fields.
     */
    private function resolveCustomerId(Order $order): ?string
    {
        $customer = null;
        if (method_exists($order, 'getCustomer')) {
            $customer = $order->getCustomer();
        }
        if ($customer === null && method_exists($order, 'getCustomerUser')) {
            $customer = $order->getCustomerUser();
        }

        return ($customer !== null && method_exists($customer, 'getId'))
            ? (string) $customer->getId()
            : null;
    }
}
