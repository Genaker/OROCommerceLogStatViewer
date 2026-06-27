<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Geocoder\GeocoderRegistry;
use Genaker\Bundle\ComiVoyager\Provider\NullWeightResolver;
use Genaker\Bundle\ComiVoyager\Provider\OrderQueryCriteria;
use Genaker\Bundle\ComiVoyager\Provider\OroOrderProvider;
use Oro\Bundle\AddressBundle\Entity\AbstractAddress;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityExtendBundle\Entity\EnumOptionInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use PHPUnit\Framework\TestCase;

class OroOrderProviderTest extends TestCase
{
    public function testFormatAddressJoinsNonEmptyParts(): void
    {
        $provider = $this->provider();
        $address = $this->address([
            'getStreet'      => '123 Main St',
            'getStreet2'     => 'Suite 4',
            'getCity'        => 'Newark',
            'getRegionName'  => 'New Jersey',
            'getPostalCode'  => '07102',
            'getCountryName' => 'United States',
        ]);

        self::assertSame(
            '123 Main St, Suite 4, Newark, New Jersey, 07102, United States',
            $provider->formatAddress($address),
        );
    }

    public function testFormatAddressSkipsEmptyParts(): void
    {
        $provider = $this->provider();
        $address = $this->address([
            'getStreet'     => '500 Industrial Pkwy',
            'getCity'       => 'Trenton',
            'getPostalCode' => '08611',
        ]);

        self::assertSame('500 Industrial Pkwy, Trenton, 08611', $provider->formatAddress($address));
    }

    public function testFormatAddressFallsBackToRegionCode(): void
    {
        $provider = $this->provider();
        $address = $this->address([
            'getStreet'     => '1 Plant Rd',
            'getCity'       => 'Camden',
            'getRegionName' => '',          // no human name
            'getRegionCode' => 'US-NJ',     // fallback
            'getPostalCode' => '08104',
        ]);

        self::assertStringContainsString('US-NJ', $provider->formatAddress($address));
    }

    public function testMatchesStatusEmptyAcceptsAll(): void
    {
        $provider = $this->provider();
        $order = $this->order('ORD-1', null, null);
        self::assertTrue($provider->matchesStatus($order, []));
    }

    public function testMatchesStatusByFullId(): void
    {
        $provider = $this->provider();
        $order = $this->order('ORD-1', null, $this->status('order_internal_status.open', 'open'));
        self::assertTrue($provider->matchesStatus($order, ['order_internal_status.open']));
    }

    public function testMatchesStatusByInternalId(): void
    {
        $provider = $this->provider();
        $order = $this->order('ORD-1', null, $this->status('order_internal_status.open', 'open'));
        self::assertTrue($provider->matchesStatus($order, ['open']));
    }

    public function testMatchesStatusNoMatch(): void
    {
        $provider = $this->provider();
        $order = $this->order('ORD-1', null, $this->status('order_internal_status.closed', 'closed'));
        self::assertFalse($provider->matchesStatus($order, ['open', 'processing']));
    }

    public function testMatchesStatusNullStatus(): void
    {
        $provider = $this->provider();
        $order = $this->order('ORD-1', null, null);
        self::assertFalse($provider->matchesStatus($order, ['open']));
    }

    public function testGetDeliveryOrdersFiltersAndGeocodes(): void
    {
        $geocoder = $this->geocoderStub([
            '10 Open St, NYC'    => new Coordinate(40.71, -74.01),
            '20 Open Ave, NYC'   => new Coordinate(40.73, -73.99),
            // "30 Bad St" is intentionally not geocodable
        ]);

        $orders = [
            $this->order('OPEN-1', $this->address(['getStreet' => '10 Open St', 'getCity' => 'NYC']),
                $this->status('order_internal_status.open', 'open')),
            $this->order('CLOSED-1', $this->address(['getStreet' => '99 Closed St', 'getCity' => 'NYC']),
                $this->status('order_internal_status.closed', 'closed')),
            $this->order('OPEN-2', $this->address(['getStreet' => '20 Open Ave', 'getCity' => 'NYC']),
                $this->status('order_internal_status.open', 'open')),
            $this->order('OPEN-3', $this->address(['getStreet' => '30 Bad St', 'getCity' => 'NYC']),
                $this->status('order_internal_status.open', 'open')),
        ];

        $provider = new class($orders, $this->registry($geocoder)) extends OroOrderProvider {
            /** @param Order[] $fixtures */
            public function __construct(private array $fixtures, GeocoderRegistry $geocoders)
            {
                parent::__construct(
                    $this->createNoopRegistry(),
                    $geocoders,
                    new NullWeightResolver(),
                );
            }
            protected function fetchOrders(OrderQueryCriteria $criteria): array
            {
                return $this->fixtures;
            }
            private function createNoopRegistry(): ManagerRegistry
            {
                return new class implements ManagerRegistry {
                    public function getDefaultConnectionName(): string { return 'default'; }
                    public function getConnection($name = null) { return null; }
                    public function getConnections(): array { return []; }
                    public function getConnectionNames(): array { return []; }
                    public function getDefaultManagerName(): string { return 'default'; }
                    public function getManager($name = null) { return null; }
                    public function getManagers(): array { return []; }
                    public function resetManager($name = null) { return null; }
                    public function getManagerForClass($class) { return null; }
                    public function getManagerNames(): array { return []; }
                    public function getRepository($persistentObject, $persistentManagerName = null) { return null; }
                    public function getAliasNamespace($alias): string { return ''; }
                };
            }
        };

        $result = $provider->getDeliveryOrders(new OrderQueryCriteria(['open']));

        // Only the two geocodable OPEN orders survive
        self::assertCount(2, $result);
        $ids = array_map(fn($o) => $o->getId(), $result);
        self::assertSame(['OPEN-1', 'OPEN-2'], $ids);
        self::assertEqualsWithDelta(40.71, $result[0]->getCoordinate()->lat, 0.001);
    }

    // --- helpers ---

    private function provider(): OroOrderProvider
    {
        return new OroOrderProvider(
            $this->createMock(ManagerRegistry::class),
            $this->registry($this->geocoderStub([])),
            new NullWeightResolver(),
        );
    }

    private function registry(GeocoderInterface $geocoder): GeocoderRegistry
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnMap([
            ['genaker_comi_voyager.geocoder', false, false, null, 'test'],
            ['genaker_comi_voyager.enable_geocode_cache', false, false, null, false],
        ]);

        return new GeocoderRegistry(
            [$geocoder],
            $config,
            $this->createMock(EntityManagerInterface::class),
        );
    }

    /** @param array<string, Coordinate> $map */
    private function geocoderStub(array $map): GeocoderInterface
    {
        return new class($map) implements GeocoderInterface {
            /** @param array<string, Coordinate> $map */
            public function __construct(private array $map) {}
            public function geocode(string $address): ?Coordinate
            {
                return $this->map[$address] ?? null;
            }
            public function getName(): string { return 'test'; }
        };
    }

    /** @param array<string, string> $getters */
    private function address(array $getters): AbstractAddress
    {
        $methods = ['getStreet', 'getStreet2', 'getCity', 'getRegionName', 'getRegionCode', 'getPostalCode', 'getCountryName', 'getCountryIso2'];
        $mock = $this->getMockBuilder(AbstractAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
        foreach ($methods as $m) {
            $mock->method($m)->willReturn($getters[$m] ?? '');
        }
        return $mock;
    }

    private function status(string $id, string $internalId): EnumOptionInterface
    {
        $status = $this->createMock(EnumOptionInterface::class);
        $status->method('getId')->willReturn($id);
        $status->method('getInternalId')->willReturn($internalId);
        return $status;
    }

    private function order(string $identifier, ?AbstractAddress $address, ?EnumOptionInterface $status): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['getInternalStatus']) // magic @method on Order
            ->onlyMethods(['getIdentifier', 'getShippingAddress', 'getCustomerUser'])
            ->getMock();
        $order->method('getIdentifier')->willReturn($identifier);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getInternalStatus')->willReturn($status);
        $order->method('getCustomerUser')->willReturn(null);
        return $order;
    }
}
