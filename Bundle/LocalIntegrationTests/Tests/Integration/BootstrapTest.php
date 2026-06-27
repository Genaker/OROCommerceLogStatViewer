<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Integration;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;

/** Integration tests verifying the module's own bootstrap and env setup. */
class BootstrapTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function testIntegrationTestsEnabled(): void
    {
        $this->assertSame('1', getenv('INTEGRATION_TESTS_ENABLED'));
    }

    /** @test */
    public function testOroDbUrlAvailable(): void
    {
        $url = getenv('ORO_DB_URL');
        $this->assertNotFalse($url);
        $this->assertNotEmpty($url);
    }

    /** @test */
    public function testKernelEnvironmentIsDev(): void
    {
        $this->assertSame('dev', self::$kernel->getEnvironment());
    }

    /** @test */
    public function testContainerHasDoctrine(): void
    {
        $container = static::getContainer();
        $this->assertTrue($container->has('doctrine'));
        $this->assertTrue($container->has('doctrine.orm.entity_manager'));
        $this->assertTrue($container->has('doctrine.dbal.default_connection'));
    }

    /** @test */
    public function testContainerHasSecurityServices(): void
    {
        $container = static::getContainer();
        $this->assertTrue($container->has('security.token_storage'));
        $this->assertTrue($container->has('oro_security.acl_helper'));
    }

    /** @test */
    public function testDatabaseConnectionFromContainer(): void
    {
        try {
            $this->initDbFromContainer();
        } catch (\Exception $exception) {
            $this->markTestSkipped('DB not available: ' . $exception->getMessage());
        }

        $result = $this->dbFetchOne('SELECT 1');
        $this->assertSame(1, (int) $result);
    }

    /** @test */
    public function testDatabaseConnectionFromEnv(): void
    {
        try {
            $this->initDbFromEnv();
        } catch (\Exception $exception) {
            $this->markTestSkipped('DB not available: ' . $exception->getMessage());
        }

        $result = $this->dbFetchOne('SELECT current_database()');
        $this->assertNotEmpty($result);

        $this->tearDownDatabaseConnection();
    }
}
