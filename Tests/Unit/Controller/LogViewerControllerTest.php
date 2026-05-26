<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Controller;

use Genaker\Bundle\LogViewerBundle\Controller\LogViewerController;
use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use Genaker\Bundle\LogViewerBundle\Service\LogFileValidator;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment as TwigEnvironment;

// phpcs:ignoreFile
// @SuppressWarnings(PHPMD.TooManyMethods)
// @SuppressWarnings(PHPMD.TooManyPublicMethods)
class LogViewerControllerTest extends TestCase
{
    private LogViewerController $controller;
    private LogFileReader $reader;
    private LogFileValidator $validator;
    private ConfigManager $configManager;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->reader        = $this->createMock(LogFileReader::class);
        $this->validator     = $this->createMock(LogFileValidator::class);
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->translator    = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $twig = $this->createMock(TwigEnvironment::class);
        $twig->method('render')->willReturn('<html></html>');

        $this->controller = new LogViewerController(
            $this->reader,
            $this->validator,
            $this->configManager,
            $this->translator,
            'test',
            $twig,
        );
    }

    /** @test */
    public function testIndexThrowsAccessDeniedWhenDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_log_viewer.enabled')
            ->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->index();
    }

    /** @test */
    public function testViewThrowsAccessDeniedWhenDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_log_viewer.enabled')
            ->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->view('app.log', new Request());
    }

    /** @test */
    public function testLiveUpdateReturnsForbiddenWhenDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_log_viewer.enabled')
            ->willReturn(false);

        $response = $this->controller->liveUpdate(new Request());

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /** @test */
    public function testLiveUpdateReturnsNewContentWhenEnabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_log_viewer.enabled')
            ->willReturn(true);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with('app.log');

        $this->reader->expects($this->once())
            ->method('readFromOffset')
            ->with('app.log', 100)
            ->willReturn(['newContent' => 'new log line', 'newOffset' => 200]);

        $request = new Request([], ['fileName' => 'app.log', 'offset' => '100']);
        $response = $this->controller->liveUpdate($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('new log line', $data['newContent']);
        $this->assertSame(200, $data['newOffset']);
    }

    /** @test */
    public function testLiveUpdateDefaultsOffsetToZero(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->reader->expects($this->once())
            ->method('readFromOffset')
            ->with('app.log', 0)
            ->willReturn(['newContent' => '', 'newOffset' => 0]);

        $request = new Request([], ['fileName' => 'app.log']);
        $this->controller->liveUpdate($request);
    }

    /** @test */
    public function testLiveUpdateCallsValidatorBeforeReader(): void
    {
        $this->configManager->method('get')->willReturn(true);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with('app.log')
            ->willThrowException(new \InvalidArgumentException('Invalid path'));

        $this->reader->expects($this->never())->method('readFromOffset');

        $this->expectException(\InvalidArgumentException::class);

        $request = new Request([], ['fileName' => 'app.log', 'offset' => '0']);
        $this->controller->liveUpdate($request);
    }

    // -------------------------------------------------------------------------
    // download() tests
    // -------------------------------------------------------------------------

    /** @test */
    public function testDownloadThrowsAccessDeniedWhenDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_log_viewer.enabled')
            ->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->download('app.log');
    }

    /** @test */
    public function testDownloadReturnsBinaryFileResponseForValidFile(): void
    {
        $tempDir  = sys_get_temp_dir() . '/log_dl_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        $logFile  = $tempDir . '/app.log';
        file_put_contents($logFile, 'log content');

        $this->configManager->method('get')->willReturn(true);
        $this->validator->expects($this->once())->method('validate')->with('app.log');
        $this->reader->method('getFullPath')->with('app.log')->willReturn($logFile);

        $response = $this->controller->download('app.log');

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        unlink($logFile);
        rmdir($tempDir);
    }

    /** @test */
    public function testDownloadSetsAttachmentFilename(): void
    {
        $tempDir  = sys_get_temp_dir() . '/log_dl_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        $logFile  = $tempDir . '/app.log';
        file_put_contents($logFile, 'content');

        $this->configManager->method('get')->willReturn(true);
        $this->reader->method('getFullPath')->willReturn($logFile);

        $response = $this->controller->download('app.log');

        $contentDisposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('app.log', $contentDisposition);

        unlink($logFile);
        rmdir($tempDir);
    }

    /** @test */
    public function testDownloadRedirectsWithFlashWhenFileNotFound(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->reader->method('getFullPath')->willReturn('/nonexistent/path/to/app.log');

        $flashBag = $this->createMock(FlashBagInterface::class);
        $flashBag->expects($this->once())->method('add')->with('error', $this->isType('string'));

        $session = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn($flashBag);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('genaker_log_viewer_index')->willReturn('/admin/logs');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['request_stack', $requestStack],
            ['router', $router],
        ]);

        $this->controller->setContainer($container);

        $response = $this->controller->download('app.log');

        $this->assertSame(302, $response->getStatusCode());
    }

    /** @test */
    public function testDownloadRedirectsWithFlashWhenValidationFails(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->validator->method('validate')->willThrowException(new \InvalidArgumentException('Bad file'));

        $flashBag = $this->createMock(FlashBagInterface::class);
        $flashBag->expects($this->once())->method('add')->with('error', $this->isType('string'));

        $session = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn($flashBag);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('genaker_log_viewer_index')->willReturn('/admin/logs');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['request_stack', $requestStack],
            ['router', $router],
        ]);

        $this->controller->setContainer($container);

        $response = $this->controller->download('app.log');

        $this->assertSame(302, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // delete() tests
    // -------------------------------------------------------------------------

    /** @test */
    public function testDeleteThrowsAccessDeniedWhenDisabled(): void
    {
        $this->configManager->method('get')->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->delete('app.log', new Request());
    }

    /** @test */
    public function testDeleteThrowsAccessDeniedOnInvalidCsrfToken(): void
    {
        $this->configManager->method('get')->willReturn(true);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(false);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->with('security.csrf.token_manager')->willReturn($csrfManager);

        $this->controller->setContainer($container);

        $this->expectException(AccessDeniedException::class);

        $request = new Request([], ['_token' => 'bad-token']);
        $this->controller->delete('app.log', $request);
    }

    /** @test */
    public function testDeleteUnlinksFileAndRedirectsWithSuccessFlash(): void
    {
        $tempDir  = sys_get_temp_dir() . '/log_del_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        $logFile  = $tempDir . '/app.log';
        file_put_contents($logFile, 'log content');

        $this->configManager->method('get')->willReturn(true);
        $this->validator->expects($this->once())->method('validate')->with('app.log');
        $this->reader->method('getFullPath')->with('app.log')->willReturn($logFile);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $flashBag = $this->createMock(FlashBagInterface::class);
        $flashBag->expects($this->once())->method('add')->with('success', $this->isType('string'));

        $session = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn($flashBag);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('genaker_log_viewer_index')->willReturn('/admin/logs');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['security.csrf.token_manager', $csrfManager],
            ['request_stack', $requestStack],
            ['router', $router],
        ]);

        $this->controller->setContainer($container);

        $request  = new Request([], ['_token' => 'valid-token']);
        $response = $this->controller->delete('app.log', $request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFileDoesNotExist($logFile);

        rmdir($tempDir);
    }

    /** @test */
    public function testDeleteRedirectsWithErrorFlashWhenFileNotFound(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->reader->method('getFullPath')->willReturn('/nonexistent/path/app.log');

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $flashBag = $this->createMock(FlashBagInterface::class);
        $flashBag->expects($this->once())->method('add')->with('error', $this->isType('string'));

        $session = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn($flashBag);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('genaker_log_viewer_index')->willReturn('/admin/logs');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['security.csrf.token_manager', $csrfManager],
            ['request_stack', $requestStack],
            ['router', $router],
        ]);

        $this->controller->setContainer($container);

        $request  = new Request([], ['_token' => 'valid-token']);
        $response = $this->controller->delete('app.log', $request);

        $this->assertSame(302, $response->getStatusCode());
    }

    /** @test */
    public function testDeleteRedirectsWithErrorFlashWhenValidationFails(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->validator->method('validate')->willThrowException(new \InvalidArgumentException('Bad file'));

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $flashBag = $this->createMock(FlashBagInterface::class);
        $flashBag->expects($this->once())->method('add')->with('error', $this->isType('string'));

        $session = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn($flashBag);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('genaker_log_viewer_index')->willReturn('/admin/logs');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['security.csrf.token_manager', $csrfManager],
            ['request_stack', $requestStack],
            ['router', $router],
        ]);

        $this->controller->setContainer($container);

        $request  = new Request([], ['_token' => 'valid-token']);
        $response = $this->controller->delete('app.log', $request);

        $this->assertSame(302, $response->getStatusCode());
    }

    /** @test */
    public function testDeleteDoesNotUnlinkWhenFileNotFound(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->reader->method('getFullPath')->willReturn('/nonexistent/app.log');

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $flashBag    = $this->createMock(FlashBagInterface::class);
        $session     = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn($flashBag);
        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/admin/logs');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['security.csrf.token_manager', $csrfManager],
            ['request_stack', $requestStack],
            ['router', $router],
        ]);
        $this->controller->setContainer($container);

        $request  = new Request([], ['_token' => 'valid-token']);
        $response = $this->controller->delete('app.log', $request);

        // File does not exist so no unlink attempted; response must still be a redirect
        $this->assertSame(302, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // uniqueEntries() tests
    // -------------------------------------------------------------------------

    /** @test */
    public function testUniqueEntriesReturnsForbiddenWhenDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_log_viewer.enabled')
            ->willReturn(false);

        $request  = new Request([], ['fileName' => 'app.log']);
        $response = $this->controller->uniqueEntries($request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /** @test */
    public function testUniqueEntriesReturnsJsonFromReader(): void
    {
        $this->configManager->method('get')->willReturn(true);
        $this->validator->expects($this->once())->method('validate')->with('app.log');

        $expectedEntries = [
            [
                'message'   => 'order_import.WARNING: Skipped field [] []',
                'level'     => 'WARNING',
                'channel'   => 'order_import',
                'count'     => 5,
                'firstSeen' => '2026-05-22T10:00:00+00:00',
                'lastSeen'  => '2026-05-22T11:00:00+00:00',
            ],
        ];

        $this->reader->expects($this->once())
            ->method('aggregateUniqueEntries')
            ->with('app.log', 10000)
            ->willReturn($expectedEntries);

        $request  = new Request([], ['fileName' => 'app.log', 'scanLines' => '10000']);
        $response = $this->controller->uniqueEntries($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame(5, $data[0]['count']);
        $this->assertSame('WARNING', $data[0]['level']);
    }

    /** @test */
    public function testUniqueEntriesClampsScanLinesToAllowedRange(): void
    {
        $this->configManager->method('get')->willReturn(true);

        $this->reader->expects($this->once())
            ->method('aggregateUniqueEntries')
            ->with('app.log', 100)   // clamped from 5 (below min 100)
            ->willReturn([]);

        $request = new Request([], ['fileName' => 'app.log', 'scanLines' => '5']);
        $this->controller->uniqueEntries($request);
    }

    /** @test */
    public function testUniqueEntriesCallsValidatorBeforeReader(): void
    {
        $this->configManager->method('get')->willReturn(true);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with('app.log')
            ->willThrowException(new \InvalidArgumentException('Bad file'));

        $this->reader->expects($this->never())->method('aggregateUniqueEntries');

        $this->expectException(\InvalidArgumentException::class);

        $request = new Request([], ['fileName' => 'app.log']);
        $this->controller->uniqueEntries($request);
    }
}
