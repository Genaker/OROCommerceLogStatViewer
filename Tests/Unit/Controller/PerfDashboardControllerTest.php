<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Controller;

use Genaker\Bundle\LogViewerBundle\Controller\PerfDashboardController;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

class PerfDashboardControllerTest extends TestCase
{
    private PerfDashboardStore $store;
    private PerfDashboardController $controller;

    protected function setUp(): void
    {
        $this->store      = $this->createMock(PerfDashboardStore::class);
        $this->controller = new PerfDashboardController($this->store);
    }

    /** @test */
    public function testInstancesReturnsJsonResponseWithAllInstances(): void
    {
        $instances = [
            ['instanceId' => 'abc123', 'hostname' => 'alpha'],
            ['instanceId' => 'def456', 'hostname' => 'beta'],
        ];

        $this->store->expects($this->once())
            ->method('loadAll')
            ->willReturn($instances);

        $response = $this->controller->instances();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame($instances, json_decode((string) $response->getContent(), true));
    }

    /** @test */
    public function testInstancesReturnsEmptyArrayWhenNoLiveInstances(): void
    {
        $this->store->method('loadAll')->willReturn([]);

        $response = $this->controller->instances();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame([], json_decode((string) $response->getContent(), true));
    }
}
