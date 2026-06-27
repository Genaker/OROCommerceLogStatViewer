<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\EventListener;

use Genaker\Bundle\LogViewerBundle\Service\BrowserConsoleLogger;
use Genaker\Bundle\LogViewerBundle\Service\PerfDashboardConfig;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Injects a <script> block before </body> that replays BrowserConsoleLogger
 * entries into the browser developer console.
 *
 * Only fires for main HTML responses (Content-Type text/html).
 * Mirrors the approach used by Symfony's WebProfilerBundle toolbar injection.
 */
class BrowserConsoleListener
{
    public function __construct(
        private readonly BrowserConsoleLogger $consoleLogger,
        private readonly PerfDashboardConfig $config,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->config->isBrowserConsoleEnabled()) {
            return;
        }

        $this->consoleLogger->setMaxEntries($this->config->getBrowserConsoleMaxEntries());
        $this->consoleLogger->setMaxPayloadBytes($this->config->getBrowserConsoleMaxSizeKb() * 1024);

        if (!$this->consoleLogger->isEnabled() || !$this->consoleLogger->hasEntries()) {
            return;
        }

        $response = $event->getResponse();

        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        if ($response->isRedirection() || $response->isEmpty()) {
            return;
        }

        $content = $response->getContent();
        if ($content === false) {
            return;
        }

        $nonce = $this->generateNonce();
        $script = $this->consoleLogger->renderScript($nonce);
        if ($script === '') {
            return;
        }

        $this->appendCspNonce($response, $nonce);

        $pos = strripos($content, '</body>');
        if ($pos !== false) {
            $content = substr($content, 0, $pos) . $script . "\n" . substr($content, $pos);
        } else {
            $content .= $script;
        }

        $response->setContent($content);
        $this->consoleLogger->clear();
    }

    private function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    private function appendCspNonce(Response $response, string $nonce): void
    {
        $nonceDirective = "'nonce-{$nonce}'";

        $csp = $response->headers->get('Content-Security-Policy');
        if ($csp !== null) {
            if (preg_match('/script-src\s+/', $csp)) {
                $csp = preg_replace(
                    '/script-src\s+/',
                    'script-src ' . $nonceDirective . ' ',
                    $csp,
                    1
                );
            } else {
                $csp .= '; script-src ' . $nonceDirective;
            }
            $response->headers->set('Content-Security-Policy', $csp);
        }
    }
}
