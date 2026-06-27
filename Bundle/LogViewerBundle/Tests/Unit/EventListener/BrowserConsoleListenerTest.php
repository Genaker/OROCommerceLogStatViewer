<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Genaker\Bundle\LogViewerBundle\EventListener\BrowserConsoleListener;
use Genaker\Bundle\LogViewerBundle\Service\BrowserConsoleLogger;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class BrowserConsoleListenerTest extends TestCase
{
    private BrowserConsoleLogger $logger;
    private BrowserConsoleListener $listener;
    private PerfDashboardConfig $config;

    protected function setUp(): void
    {
        $this->logger = new BrowserConsoleLogger();
        $this->config = $this->createMock(PerfDashboardConfig::class);
        $this->config->method('isBrowserConsoleEnabled')->willReturn(true);
        $this->config->method('getBrowserConsoleMaxEntries')->willReturn(200);
        $this->config->method('getBrowserConsoleMaxSizeKb')->willReturn(128);
        $this->listener = new BrowserConsoleListener($this->logger, $this->config);
    }

    public function testInjectsScriptBeforeBodyClose(): void
    {
        $this->logger->log('Backend info', ['key' => 'val']);

        $response = new Response('<html><body><p>Hello</p></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        $content = $response->getContent();
        self::assertStringContainsString('<script data-browser-console-logger', $content);
        self::assertStringContainsString('[PHP] Backend info', $content);

        $scriptPos = strpos($content, '<script data-browser-console-logger');
        $bodyPos = strpos($content, '</body>');
        self::assertLessThan($bodyPos, $scriptPos);
    }

    public function testSkipsNonHtmlResponse(): void
    {
        $this->logger->log('data');

        $response = new Response('{"json":true}');
        $response->headers->set('Content-Type', 'application/json');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        self::assertSame('{"json":true}', $response->getContent());
    }

    public function testSkipsSubRequest(): void
    {
        $this->logger->log('data');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response, HttpKernelInterface::SUB_REQUEST);
        $this->listener->onKernelResponse($event);

        self::assertStringNotContainsString('<script', $response->getContent());
    }

    public function testSkipsWhenNoEntries(): void
    {
        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        self::assertStringNotContainsString('<script', $response->getContent());
    }

    public function testSkipsRedirect(): void
    {
        $this->logger->log('data');

        $response = new Response('', 302, ['Location' => '/other']);
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        self::assertSame('', $response->getContent());
    }

    public function testSkipsWhenConfigDisabled(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isBrowserConsoleEnabled')->willReturn(false);
        $listener = new BrowserConsoleListener($this->logger, $config);

        $this->logger->log('should not appear');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('<script', $response->getContent());
    }

    public function testClearsEntriesAfterInjection(): void
    {
        $this->logger->log('once');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        self::assertFalse($this->logger->hasEntries());
    }

    public function testAppendsWhenNoBodyTag(): void
    {
        $this->logger->log('no body tag');

        $response = new Response('<html><p>fragment</p>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        $content = $response->getContent();
        self::assertStringContainsString('<script data-browser-console-logger', $content);
        self::assertStringStartsWith('<html><p>fragment</p>', $content);
    }

    public function testInjectsNonceAttribute(): void
    {
        $this->logger->log('nonce test');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        $content = $response->getContent();
        self::assertMatchesRegularExpression('/nonce="[A-Za-z0-9+\/=]+"/', $content);
    }

    public function testAppendsCspNonceToExistingCspWithScriptSrc(): void
    {
        $this->logger->log('csp test');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'");

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        $csp = $response->headers->get('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=]+'/", $csp);
        self::assertStringContainsString("script-src 'nonce-", $csp);
    }

    public function testAppendsCspNonceToExistingCspWithoutScriptSrc(): void
    {
        $this->logger->log('csp test');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->set('Content-Security-Policy', "default-src 'self'");

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        $csp = $response->headers->get('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("; script-src 'nonce-", $csp);
    }

    public function testNoCspHeaderWhenNoneExists(): void
    {
        $this->logger->log('no csp');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $this->listener->onKernelResponse($event);

        self::assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function testAppliesConfigLimits(): void
    {
        $config = $this->createMock(PerfDashboardConfig::class);
        $config->method('isBrowserConsoleEnabled')->willReturn(true);
        $config->method('getBrowserConsoleMaxEntries')->willReturn(50);
        $config->method('getBrowserConsoleMaxSizeKb')->willReturn(64);
        $listener = new BrowserConsoleListener($this->logger, $config);

        $this->logger->log('data');

        $response = new Response('<html><body></body></html>');
        $response->headers->set('Content-Type', 'text/html');

        $event = $this->createResponseEvent($response);
        $listener->onKernelResponse($event);

        self::assertSame(50, $this->logger->getMaxEntries());
        self::assertSame(64 * 1024, $this->logger->getMaxPayloadBytes());
    }

    private function createResponseEvent(
        Response $response,
        int $requestType = HttpKernelInterface::MAIN_REQUEST
    ): ResponseEvent {
        return new ResponseEvent(
            $this->createMock(KernelInterface::class),
            new Request(),
            $requestType,
            $response
        );
    }
}
