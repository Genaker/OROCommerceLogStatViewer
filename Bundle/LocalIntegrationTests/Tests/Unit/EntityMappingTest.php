<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit;

use Egerdau\Bundle\ShipmentGridBundle\Entity\Shipment;
use Egerdau\Bundle\ShipmentGridBundle\Entity\ShipmentLineItem;
use Egerdau\Bundle\ShipmentGridBundle\Entity\ShipmentLineItemGridView;
use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;

/** Validates Doctrine entity mappings are correct. */
class EntityMappingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function testShipmentMappingIsValid(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $entityManager->getClassMetadata(Shipment::class);

        $this->assertSame('egerdau_shipment', $metadata->getTableName());
        $this->assertTrue($metadata->hasAssociation('customerUser'));
        $this->assertTrue($metadata->hasAssociation('customer'));
        $this->assertTrue($metadata->hasAssociation('lineItems'));
    }

    /** @test */
    public function testShipmentCustomerAssociationMapping(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $entityManager->getClassMetadata(Shipment::class);

        $customerMapping = $metadata->getAssociationMapping('customer');
        $this->assertSame('Oro\Bundle\CustomerBundle\Entity\Customer', $customerMapping['targetEntity']);

        $customerUserMapping = $metadata->getAssociationMapping('customerUser');
        $this->assertSame('Oro\Bundle\CustomerBundle\Entity\CustomerUser', $customerUserMapping['targetEntity']);
    }

    /** @test */
    public function testLineItemMappingIsValid(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $entityManager->getClassMetadata(ShipmentLineItem::class);

        $this->assertSame('egerdau_shipment_line_item', $metadata->getTableName());
        $this->assertTrue($metadata->hasAssociation('shipment'));
        $this->assertTrue($metadata->hasAssociation('order'));
        $this->assertTrue($metadata->hasAssociation('orderLineItem'));
    }

    /** @test */
    public function testLineItemOrderAssociationMapping(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $entityManager->getClassMetadata(ShipmentLineItem::class);

        $orderMapping = $metadata->getAssociationMapping('order');
        $this->assertSame('Oro\Bundle\OrderBundle\Entity\Order', $orderMapping['targetEntity']);

        $oliMapping = $metadata->getAssociationMapping('orderLineItem');
        $this->assertSame('Oro\Bundle\OrderBundle\Entity\OrderLineItem', $oliMapping['targetEntity']);
    }

    /** @test */
    public function testGridViewMappingIsReadOnly(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $entityManager->getClassMetadata(ShipmentLineItemGridView::class);

        $this->assertSame('shipment_line_item_grid_view', $metadata->getTableName());
        $this->assertTrue($metadata->isReadOnly);
    }
}
