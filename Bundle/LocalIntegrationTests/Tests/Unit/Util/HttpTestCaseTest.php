<?php

// phpcs:ignoreFile

declare(strict_types=1);

namespace Genaker\Bundle\LocalIntegrationTests\Tests\Unit\Util;

use Genaker\Bundle\LocalIntegrationTests\Util\HttpTestCase;
use PHPUnit\Framework\TestCase;

/** Unit tests for HttpTestCase SSL configuration. */
class HttpTestCaseTest extends TestCase
{
    private ?string $originalSslVerify = null;

    protected function setUp(): void
    {
        $this->originalSslVerify = getenv('ORO_TEST_SSL_VERIFY') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalSslVerify !== null) {
            putenv('ORO_TEST_SSL_VERIFY=' . $this->originalSslVerify);
        } else {
            putenv('ORO_TEST_SSL_VERIFY');
        }
    }

    /** @test */
    public function testSslVerifyDisabledByDefault(): void
    {
        putenv('ORO_TEST_SSL_VERIFY');

        $instance = $this->createHttpTestCase();
        $method = new \ReflectionMethod($instance, 'isSslVerifyEnabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($instance));
    }

    /** @test */
    public function testSslVerifyDisabledWhenSetToZero(): void
    {
        putenv('ORO_TEST_SSL_VERIFY=0');

        $instance = $this->createHttpTestCase();
        $method = new \ReflectionMethod($instance, 'isSslVerifyEnabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($instance));
    }

    /** @test */
    public function testSslVerifyDisabledWhenSetToFalse(): void
    {
        putenv('ORO_TEST_SSL_VERIFY=false');

        $instance = $this->createHttpTestCase();
        $method = new \ReflectionMethod($instance, 'isSslVerifyEnabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($instance));
    }

    /** @test */
    public function testSslVerifyEnabledWhenSetToOne(): void
    {
        putenv('ORO_TEST_SSL_VERIFY=1');

        $instance = $this->createHttpTestCase();
        $method = new \ReflectionMethod($instance, 'isSslVerifyEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($instance));
    }

    /** @test */
    public function testSslVerifyEnabledWhenSetToTrue(): void
    {
        putenv('ORO_TEST_SSL_VERIFY=true');

        $instance = $this->createHttpTestCase();
        $method = new \ReflectionMethod($instance, 'isSslVerifyEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($instance));
    }

    /** @test */
    public function testSslVerifyMethodExists(): void
    {
        $reflection = new \ReflectionClass(HttpTestCase::class);
        $this->assertTrue($reflection->hasMethod('isSslVerifyEnabled'));
    }

    private function createHttpTestCase(): HttpTestCase
    {
        return new class extends HttpTestCase {
            public function __construct()
            {
                // Skip parent TestCase constructor side effects
            }
        };
    }
}
