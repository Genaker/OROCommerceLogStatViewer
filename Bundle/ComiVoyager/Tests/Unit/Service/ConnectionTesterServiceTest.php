<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Service;

use Genaker\Bundle\ComiVoyager\Service\ConnectionTesterService;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Service\ConnectionTesterService
 */
final class ConnectionTesterServiceTest extends TestCase
{
    public function testTestOsrmSucceedsWhenResponseCodeIsOk(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['code' => 'Ok']));
        $configManager = $this->createMock(ConfigManager::class);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testOsrm('https://router.project-osrm.org');

        self::assertTrue($result['success']);
        self::assertStringContainsString('router.project-osrm.org', $result['message']);
    }

    public function testTestOsrmFailsWhenResponseCodeIsNotOk(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['code' => 'NoRoute']));
        $configManager = $this->createMock(ConfigManager::class);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testOsrm('https://router.project-osrm.org');

        self::assertFalse($result['success']);
        self::assertStringContainsString('NoRoute', $result['message']);
    }

    public function testTestOsrmFailsOnTransportError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $configManager = $this->createMock(ConfigManager::class);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testOsrm('https://router.project-osrm.org');

        self::assertFalse($result['success']);
        self::assertStringContainsString('Could not connect to OSRM', $result['message']);
    }

    public function testTestOsrmFallsBackToStoredConfigWhenValueIsEmpty(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['code' => 'Ok']));
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')
            ->with('genaker_comi_voyager.osrm_base_url')
            ->willReturn('https://stored-osrm.example.com');
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testOsrm('');

        self::assertTrue($result['success']);
        self::assertStringContainsString('stored-osrm.example.com', $result['message']);
    }

    public function testTestOsrmFailsWhenNoBaseUrlConfigured(): void
    {
        $httpClient = new MockHttpClient();
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testOsrm('');

        self::assertFalse($result['success']);
        self::assertSame('No OSRM base URL configured.', $result['message']);
    }

    public function testTestGoogleSucceedsWhenStatusIsOk(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['status' => 'OK']));
        $configManager = $this->createMock(ConfigManager::class);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testGoogle('AIzaSyTestKey');

        self::assertTrue($result['success']);
        self::assertSame('Google API key is valid.', $result['message']);
    }

    public function testTestGoogleFailsWhenStatusIsNotOk(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['status' => 'REQUEST_DENIED']));
        $configManager = $this->createMock(ConfigManager::class);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testGoogle('bad-key');

        self::assertFalse($result['success']);
        self::assertStringContainsString('REQUEST_DENIED', $result['message']);
    }

    public function testTestGoogleFallsBackToDecryptedStoredKeyWhenValueIsPlaceholder(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['status' => 'OK']));
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')
            ->with('genaker_comi_voyager.google_api_key')
            ->willReturn('encrypted-value');
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->expects(self::once())
            ->method('decryptData')
            ->with('encrypted-value')
            ->willReturn('decrypted-api-key');

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testGoogle('********');

        self::assertTrue($result['success']);
    }

    public function testTestGoogleFailsWhenNoApiKeyConfigured(): void
    {
        $httpClient = new MockHttpClient();
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn('');
        $crypter = $this->createMock(SymmetricCrypterInterface::class);

        $service = new ConnectionTesterService($httpClient, $configManager, $crypter);

        $result = $service->testGoogle(null);

        self::assertFalse($result['success']);
        self::assertSame('No Google API key configured.', $result['message']);
    }
}
