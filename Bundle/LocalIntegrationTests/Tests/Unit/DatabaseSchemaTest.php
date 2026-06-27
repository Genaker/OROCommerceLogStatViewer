<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;

/** Validates database schema for shipment tables. */
class DatabaseSchemaTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->initDbFromEnv();
        } catch (\Exception $exception) {
            $this->markTestSkipped('DB not available: ' . $exception->getMessage());
        }
    }

    /** @test */
    public function testShipmentTableColumns(): void
    {
        $columns = $this->getTableColumns('egerdau_shipment');

        $this->assertContains('id', $columns);
        $this->assertContains('customer_user_id', $columns);
        $this->assertContains('customer_id', $columns);
        $this->assertContains('shipment_number', $columns);
        $this->assertContains('vehicle_number', $columns);
        $this->assertContains('delivery_numbers', $columns);
    }

    /** @test */
    public function testLineItemTableColumns(): void
    {
        $columns = $this->getTableColumns('egerdau_shipment_line_item');

        $this->assertContains('id', $columns);
        $this->assertContains('shipment_id', $columns);
        $this->assertContains('order_id', $columns);
        $this->assertContains('order_line_item_id', $columns);
        $this->assertContains('sku', $columns);
        $this->assertContains('sales_order_number', $columns);
    }

    /** @test */
    public function testDeliveryTableColumns(): void
    {
        $columns = $this->getTableColumns('egerdau_delivery');

        $this->assertContains('id', $columns);
        $this->assertContains('delivery_number', $columns);
        $this->assertContains('sales_order_number', $columns);
        $this->assertContains('ship_to_party', $columns);
        $this->assertContains('sold_to_party', $columns);
    }

    /** @test */
    public function testShipmentIndexesExist(): void
    {
        $indexes = $this->getTableIndexes('egerdau_shipment');

        $this->assertContains('idx_shipment_customer_user_id', $indexes);
        $this->assertContains('idx_shipment_customer_id', $indexes);
        $this->assertContains('idx_shipment_number', $indexes);
    }

    /** @test */
    public function testViewExists(): void
    {
        $isView = (bool) $this->dbFetchOne(
            "SELECT 1 FROM information_schema.views
             WHERE table_name = 'shipment_line_item_grid_view' AND table_schema = 'public'"
        );
        $isMaterialized = (bool) $this->dbFetchOne(
            "SELECT 1 FROM pg_matviews
             WHERE matviewname = 'shipment_line_item_grid_view' AND schemaname = 'public'"
        );

        $this->assertTrue($isView || $isMaterialized, 'View must exist as view or materialized view');
    }

    /** @test */
    public function testViewHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('shipment_line_item_grid_view');

        $required = [
            'sli_id', 's_id', 's_shipment_number', 's_customer_user_id',
            's_customer_id', 'organization_id', 'd_delivery_number',
            'd_delivery_status', 'sli_sku', 'total_price',
        ];

        foreach ($required as $column) {
            $this->assertContains($column, $columns, "View must have column: $column");
        }
    }

    private function getTableColumns(string $table): array
    {
        return array_column($this->dbQuery(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = ? AND table_schema = 'public'
             ORDER BY ordinal_position",
            [$table]
        ), 'column_name');
    }

    private function getTableIndexes(string $table): array
    {
        return array_column($this->dbQuery(
            "SELECT indexname FROM pg_indexes
             WHERE tablename = ? AND schemaname = 'public'",
            [$table]
        ), 'indexname');
    }
}
