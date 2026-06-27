<?php

// phpcs:ignoreFile
// Run: php bin/phpunit -c src/Genaker/Bundle/LocalIntegrationTests/phpunit-local.xml

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;

/**
 * Self-test for the local integration test framework.
 *
 * Verifies that the framework boots correctly, connects to the database,
 * and provides working query helpers — independent of any business logic.
 */
class FrameworkSelfTest extends IntegrationTestCase
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
    public function testDatabaseConnectionWorks(): void
    {
        $result = $this->dbFetchOne('SELECT 1 AS ping');
        $this->assertSame(1, (int) $result);
    }

    /** @test */
    public function testKernelBoots(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel, 'Kernel must be booted');
        $this->assertSame('dev', $kernel->getEnvironment());
    }

    /** @test */
    public function testContainerAvailable(): void
    {
        $container = static::getContainer();
        $this->assertNotNull($container);
        $this->assertTrue(
            $container->has('doctrine.orm.entity_manager'),
            'EntityManager must be available in container'
        );
    }

    /** @test */
    public function testDbQueryReturnsRows(): void
    {
        $rows = $this->dbQuery('SELECT 1 AS val UNION SELECT 2');
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]['val']);
        $this->assertSame(2, (int) $rows[1]['val']);
    }

    /** @test */
    public function testDbFetchRowReturnsAssociative(): void
    {
        $row = $this->dbFetchRow("SELECT 'hello' AS greeting");
        $this->assertNotNull($row);
        $this->assertSame('hello', $row['greeting']);
    }

    /** @test */
    public function testDbFetchRowReturnsNullOnEmpty(): void
    {
        $row = $this->dbFetchRow('SELECT 1 WHERE 1 = 0');
        $this->assertNull($row);
    }

    /** @test */
    public function testOroTablesExist(): void
    {
        $exists = (bool) $this->dbFetchOne(
            "SELECT 1 FROM information_schema.tables
             WHERE table_name = 'oro_organization' AND table_schema = 'public'"
        );
        $this->assertTrue($exists, 'oro_organization table must exist');
    }

    /** @test */
    public function testShipmentTablesExist(): void
    {
        $tables = ['egerdau_shipment', 'egerdau_shipment_line_item', 'egerdau_delivery'];
        foreach ($tables as $table) {
            $exists = (bool) $this->dbFetchOne(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_name = ? AND table_schema = 'public'",
                [$table]
            );
            $this->assertTrue($exists, sprintf('Table %s must exist', $table));
        }
    }

    /** @test */
    public function testEntityManagerCanQueryShipments(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $count = (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM egerdau_shipment'
        );
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
