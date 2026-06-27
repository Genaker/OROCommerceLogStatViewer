<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit\Util;

use Genaker\Bundle\LocalIntegrationTests\Util\DatabaseTestTrait;
use PHPUnit\Framework\TestCase;

/** Unit tests for the DatabaseTestTrait utility. */
class DatabaseTestTraitTest extends TestCase
{
    /** @test */
    public function testTraitExists(): void
    {
        $this->assertTrue(
            trait_exists(DatabaseTestTrait::class),
            'DatabaseTestTrait must exist in the Genaker namespace'
        );
    }

    /** @test */
    public function testTraitProvidesInitDbFromEnv(): void
    {
        $reflection = new \ReflectionClass($this->createTraitUser());
        $this->assertTrue($reflection->hasMethod('initDbFromEnv'));
    }

    /** @test */
    public function testTraitProvidesInitDbFromContainer(): void
    {
        $reflection = new \ReflectionClass($this->createTraitUser());
        $this->assertTrue($reflection->hasMethod('initDbFromContainer'));
    }

    /** @test */
    public function testTraitProvidesQueryHelpers(): void
    {
        $reflection = new \ReflectionClass($this->createTraitUser());

        $this->assertTrue($reflection->hasMethod('dbQuery'));
        $this->assertTrue($reflection->hasMethod('dbFetchOne'));
        $this->assertTrue($reflection->hasMethod('dbFetchRow'));
        $this->assertTrue($reflection->hasMethod('dbExecute'));
    }

    /** @test */
    public function testTraitProvidesTeardown(): void
    {
        $reflection = new \ReflectionClass($this->createTraitUser());
        $this->assertTrue($reflection->hasMethod('tearDownDatabaseConnection'));
    }

    /** @test */
    public function testTraitProvidesSkipHelper(): void
    {
        $reflection = new \ReflectionClass($this->createTraitUser());
        $this->assertTrue($reflection->hasMethod('skipIfDbNotAvailable'));
    }

    private function createTraitUser(): object
    {
        return new class {
            use DatabaseTestTrait;
        };
    }
}
