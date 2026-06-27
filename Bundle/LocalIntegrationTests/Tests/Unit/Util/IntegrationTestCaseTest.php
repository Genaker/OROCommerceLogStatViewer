<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit\Util;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Unit tests for the IntegrationTestCase base class. */
class IntegrationTestCaseTest extends TestCase
{
    /** @test */
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(IntegrationTestCase::class));
    }

    /** @test */
    public function testExtendsKernelTestCase(): void
    {
        $reflection = new \ReflectionClass(IntegrationTestCase::class);
        $this->assertTrue($reflection->isSubclassOf(KernelTestCase::class));
    }

    /** @test */
    public function testIsAbstract(): void
    {
        $reflection = new \ReflectionClass(IntegrationTestCase::class);
        $this->assertTrue($reflection->isAbstract());
    }

    /** @test */
    public function testUsesDatabaseTestTrait(): void
    {
        $traits = class_uses(IntegrationTestCase::class) ?: [];
        $allTraits = $this->getAllTraits(IntegrationTestCase::class);

        $this->assertTrue(
            in_array('Genaker\Bundle\LocalIntegrationTests\Util\DatabaseTestTrait', $allTraits, true),
            'IntegrationTestCase must use DatabaseTestTrait'
        );
    }

    /** @test */
    public function testProvidesContainerAccess(): void
    {
        $reflection = new \ReflectionClass(IntegrationTestCase::class);
        $this->assertTrue($reflection->hasMethod('getContainer'));
    }

    /** @test */
    public function testProvidesRequestMethod(): void
    {
        $reflection = new \ReflectionClass(IntegrationTestCase::class);
        $this->assertTrue($reflection->hasMethod('request'));
    }

    /** @test */
    public function testProvidesHttpHelpers(): void
    {
        $reflection = new \ReflectionClass(IntegrationTestCase::class);

        $this->assertTrue($reflection->hasMethod('get'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('put'));
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    /** @test */
    public function testKernelClassIsAppKernel(): void
    {
        $method = new \ReflectionMethod(IntegrationTestCase::class, 'getKernelClass');
        $method->setAccessible(true);

        $this->assertSame(\AppKernel::class, $method->invoke(null));
    }

    private function getAllTraits(string $class): array
    {
        $traits = [];
        $current = $class;

        while ($current) {
            $traits = array_merge($traits, class_uses($current) ?: []);
            $current = get_parent_class($current);
        }

        return array_values(array_unique($traits));
    }
}
