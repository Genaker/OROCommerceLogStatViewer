<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Controller;

use Genaker\Bundle\ComiVoyager\Controller\ConnectionTestController;
use Genaker\Bundle\ComiVoyager\Service\ConnectionTesterService;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Controller\ConnectionTestController
 *
 * ConnectionTesterService is final and cannot be mocked, so these tests build
 * a real instance with a MockHttpClient (as in ConnectionTesterServiceTest)
 * and exercise the controller's routing/JSON-response behavior.
 */
final class ConnectionTestControllerTest extends TestCase
{
    private function controller(MockHttpClient $httpClient): ConnectionTestController
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $controller = new ConnectionTestController($service);
        $controller->setContainer(new Container());

        return $controller;
    }

    public function testOsrmProviderTestsTheSubmittedUrl(): void
    {
        $controller = $this->controller(new MockHttpClient(new JsonMockResponse(['code' => 'Ok'])));

        $request = new Request([], ['value' => 'https://router.project-osrm.org']);
        $response = $controller->testAction($request, 'osrm');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertStringContainsString('router.project-osrm.org', $payload['message']);
    }

    public function testGoogleProviderTestsTheSubmittedKey(): void
    {
        $controller = $this->controller(new MockHttpClient(new JsonMockResponse(['status' => 'REQUEST_DENIED'])));

        $request = new Request([], ['value' => 'bad-key']);
        $response = $controller->testAction($request, 'google');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertStringContainsString('REQUEST_DENIED', $payload['message']);
    }

    public function testMissingValueFallsBackToNoStoredConfig(): void
    {
        $controller = $this->controller(new MockHttpClient());

        $response = $controller->testAction(new Request(), 'osrm');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertSame('No OSRM base URL configured.', $payload['message']);
    }

    public function testUnknownProviderReturnsErrorWithoutCallingHttpClient(): void
    {
        $httpClient = new MockHttpClient();
        $controller = $this->controller($httpClient);

        $response = $controller->testAction(new Request([], ['value' => 'irrelevant']), 'bing');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertStringContainsString('Unknown connection test "bing"', $payload['message']);
        self::assertSame(0, $httpClient->getRequestsCount());
    }
}
