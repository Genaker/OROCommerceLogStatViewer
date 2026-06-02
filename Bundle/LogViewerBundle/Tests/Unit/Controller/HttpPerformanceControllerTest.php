<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Controller;

use Genaker\Bundle\LogViewerBundle\Controller\HttpPerformanceController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class HttpPerformanceControllerTest extends TestCase
{
    /** @test */
    public function testControllerExtendsAbstractController(): void
    {
        $controller = new HttpPerformanceController();

        $this->assertInstanceOf(AbstractController::class, $controller);
    }

    /** @test */
    public function testIndexActionMethodExists(): void
    {
        $this->assertTrue(method_exists(HttpPerformanceController::class, 'indexAction'));
    }

    /** @test */
    public function testControllerHasExpectedRouteAttribute(): void
    {
        $reflection = new \ReflectionMethod(HttpPerformanceController::class, 'indexAction');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Annotation\Route::class);

        $this->assertNotEmpty($attributes, 'indexAction must have a #[Route] attribute');

        $route = $attributes[0]->newInstance();
        $this->assertSame('/admin/http-performance', $route->getPath());
        $this->assertSame('genaker_http_performance_index', $route->getName());
    }
}
