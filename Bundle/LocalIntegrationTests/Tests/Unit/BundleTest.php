<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit;

use Genaker\Bundle\LocalIntegrationTests\GenakerLocalIntegrationTestsBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** Unit tests for the bundle class itself. */
class BundleTest extends TestCase
{
    /** @test */
    public function testBundleClassExists(): void
    {
        $this->assertTrue(class_exists(GenakerLocalIntegrationTestsBundle::class));
    }

    /** @test */
    public function testExtendsSymfonyBundle(): void
    {
        $bundle = new GenakerLocalIntegrationTestsBundle();
        $this->assertInstanceOf(Bundle::class, $bundle);
    }

    /** @test */
    public function testBootstrapFileExists(): void
    {
        $bootstrapPath = dirname(__DIR__, 2) . '/bootstrap.php';
        $this->assertFileExists($bootstrapPath);
    }

    /** @test */
    public function testPhpunitConfigExists(): void
    {
        $configPath = dirname(__DIR__, 2) . '/phpunit-local.xml';
        $this->assertFileExists($configPath);
    }

    /** @test */
    public function testPhpunitConfigHasTestSuites(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/phpunit-local.xml');

        $this->assertStringContainsString('testsuite name="unit"', $content);
        $this->assertStringContainsString('testsuite name="integration"', $content);
    }

    /** @test */
    public function testUtilClassesExist(): void
    {
        $this->assertTrue(class_exists('Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase'));
        $this->assertTrue(trait_exists('Genaker\Bundle\LocalIntegrationTests\Util\DatabaseTestTrait'));
    }
}
