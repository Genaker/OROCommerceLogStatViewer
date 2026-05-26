<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Controller;

use Genaker\Bundle\LogViewerBundle\Service\LogFileReader;
use Genaker\Bundle\LogViewerBundle\Service\LogFileValidator;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Provides admin interface for viewing, downloading, and deleting application logs.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
#[Route('/admin/logs', name: 'genaker_log_viewer_')]
class LogViewerController extends AbstractController
{
    private const string LOG_VIEWER_DISABLED_MESSAGE_KEY = 'genaker.log_viewer.disabled_message';
    private const string DELETE_SUCCESS_MESSAGE_KEY = 'genaker.log_viewer.delete_msg';
    private const string FILE_ERROR_MESSAGE_KEY = 'genaker.log_viewer.file_error';

    public function __construct(
        private readonly LogFileReader    $reader,
        private readonly LogFileValidator $validator,
        private readonly ConfigManager    $configManager,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'index')]
    #[AclAncestor('genaker_log_viewer_index')]
    public function index(): Response
    {
        if (!$this->isEnabled()) {
            throw $this->createAccessDeniedException($this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY));
        }

        $content = $this->twig->render('@GenakerLogViewer/LogViewer/index.html.twig', [
            'opcache'  => $this->getOpcacheStats(),
            'xdebug'   => $this->getXdebugInfo(),
            'logFiles' => $this->reader->getLogFiles(),
        ]);
        return new Response($content);
    }

    private function getOpcacheStats(): array
    {
        if (!function_exists('opcache_get_status') || !function_exists('opcache_get_configuration')) {
            return ['enabled' => false];
        }

        $status = opcache_get_status(false);
        $config = opcache_get_configuration();

        if ($status === false) {
            return ['enabled' => false];
        }

        $memUsed  = $status['memory_usage']['used_memory'] ?? 0;
        $memFree  = $status['memory_usage']['free_memory'] ?? 0;
        $memWaste = $status['memory_usage']['wasted_memory'] ?? 0;
        $memTotal = $memUsed + $memFree + $memWaste;

        $maxFiles        = (int) ($config['directives']['opcache.max_accelerated_files'] ?? 0);
        $memAllowedRaw   = (int) ($config['directives']['opcache.memory_consumption'] ?? 0);
        // PHP may return bytes or MB depending on version; normalize to MB
        $memAllowed = $memAllowedRaw >= 1048576 ? (int) round($memAllowedRaw / 1048576) : $memAllowedRaw;

        return [
            'enabled'              => true,
            'files_cached'         => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
            'max_files'            => $maxFiles,
            'memory_used_mb'       => round($memUsed / 1048576, 2),
            'memory_allowed_mb'    => $memAllowed,
            'memory_used_percent'  => $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : 0,
            'timestamp_validation' => (bool) ($config['directives']['opcache.validate_timestamps'] ?? true),
            'hits'                 => $status['opcache_statistics']['hits'] ?? 0,
            'misses'               => $status['opcache_statistics']['misses'] ?? 0,
        ];
    }

    private function getXdebugInfo(): array
    {
        $loaded = extension_loaded('xdebug');
        if (!$loaded) {
            return ['loaded' => false];
        }

        $version = phpversion('xdebug') ?: 'unknown';
        $rawMode = (string) ini_get('xdebug.mode');
        $mode    = $rawMode !== '' ? $rawMode : 'unknown';

        return [
            'loaded'  => true,
            'version' => $version,
            'mode'    => $mode,
        ];
    }

    #[Route('/view/{fileName}', name: 'view', requirements: ['fileName' => '.+'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function view(string $fileName, Request $request): Response
    {
        if (!$this->isEnabled()) {
            throw $this->createAccessDeniedException($this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY));
        }

        if ($request->query->has('grid') || $request->query->has('a')) {
            return $this->redirectToRoute('genaker_log_viewer_view', ['fileName' => $fileName]);
        }

        $this->validator->validate($fileName);
        $lines     = max(10, min(1000, (int) $request->query->get('lines', 100)));
        $grepQuery = trim((string) $request->query->get('grep', ''));
        $grepFull  = $request->query->getBoolean('grepFull', false);

        [$content, $offset, $readMs] = $this->readLogContent($fileName, $lines, $grepQuery, $grepFull);

        $html = $this->twig->render('@GenakerLogViewer/LogViewer/view.html.twig', [
            'fileName'   => $fileName,
            'logContent' => $content,
            'logOffset'  => $offset,
            'lines'      => $lines,
            'grepQuery'  => $grepQuery,
            'grepFull'   => $grepFull,
            'stats'      => $this->buildViewStats($fileName, $content, $readMs, $grepQuery, $grepFull),
            'logFiles'   => $this->reader->getLogFiles(),
        ]);

        return new Response($html);
    }

    private function readLogContent(
        string $fileName,
        int $lines,
        string $grepQuery,
        bool $grepFull
    ): array {
        $readStart = microtime(true);

        if ($grepQuery !== '') {
            $content = $this->reader->readGrep($fileName, $grepQuery, $lines, $grepFull);
            $offset  = 0;
        } else {
            ['content' => $content, 'offset' => $offset] = $this->reader->readTail($fileName, $lines);
        }

        $readMs = round((microtime(true) - $readStart) * 1000, 1);

        return [$content, $offset, $readMs];
    }

    private function buildViewStats(
        string $fileName,
        string $content,
        float $readMs,
        string $grepQuery,
        bool $grepFull
    ): array {
        $stat      = @stat($this->reader->getFullPath($fileName));
        $lineCount = substr_count($content, "\n") + (strlen($content) > 0 ? 1 : 0);
        $mode      = $this->resolveMode($grepQuery, $grepFull);

        return [
            'readMs'    => $readMs,
            'fileSize'  => $stat !== false ? $this->reader->formatBytesPublic($stat['size']) : 'N/A',
            'lineCount' => $lineCount,
            'loadedAt'  => date('H:i:s'),
            'mode'      => $mode,
        ];
    }

    private function resolveMode(string $grepQuery, bool $grepFull): string
    {
        if ($grepQuery === '') {
            return 'tail';
        }

        return $grepFull ? 'grep (full file)' : 'grep (last 50 MB)';
    }

    #[Route('/live-update', name: 'live_update', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function liveUpdate(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileName = (string) $request->request->get('fileName', '');
        $offset   = (int) $request->request->get('offset', 0);

        $this->validator->validate($fileName);

        return new JsonResponse($this->reader->readFromOffset($fileName, $offset));
    }

    #[Route('/reload', name: 'reload', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function reload(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileName = (string) $request->request->get('fileName', '');
        $lines    = max(10, min(10000, (int) $request->request->get('lines', 100)));

        $this->validator->validate($fileName);

        $readStart = microtime(true);
        ['content' => $content, 'offset' => $offset] = $this->reader->readTail($fileName, $lines);
        $readMs = round((microtime(true) - $readStart) * 1000, 1);

        $stat = @stat($this->reader->getFullPath($fileName));

        return new JsonResponse([
            'content'   => $content,
            'offset'    => $offset,
            'lineCount' => substr_count($content, "\n") + 1,
            'readMs'    => $readMs,
            'fileSize'  => $stat ? $this->reader->formatBytesPublic($stat['size']) : '?',
            'loadedAt'  => date('H:i:s'),
        ]);
    }

    #[Route('/exceptions', name: 'exceptions', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function exceptions(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileName  = (string) $request->request->get('fileName', '');
        $scanLines = max(100, min(100000, (int) $request->request->get('scanLines', 10000)));

        $this->validator->validate($fileName);

        return new JsonResponse($this->reader->aggregateExceptions($fileName, $scanLines));
    }

    #[Route('/unique-entries', name: 'unique_entries', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function uniqueEntries(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileName  = (string) $request->request->get('fileName', '');
        $scanLines = max(100, min(100000, (int) $request->request->get('scanLines', 10000)));

        $this->validator->validate($fileName);

        return new JsonResponse($this->reader->aggregateUniqueEntries($fileName, $scanLines));
    }

    #[Route('/throughput', name: 'throughput', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function throughput(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileName  = (string) $request->request->get('fileName', '');
        $scanLines = max(100, min(50000, (int) $request->request->get('scanLines', 5000)));

        $this->validator->validate($fileName);

        return new JsonResponse($this->reader->getThroughput($fileName, $scanLines));
    }

    #[Route('/grep', name: 'grep', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function grep(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileName = (string) $request->request->get('fileName', '');
        $pattern  = trim((string) $request->request->get('pattern', ''));
        $lines    = max(10, min(10000, (int) $request->request->get('lines', 100)));
        $fullScan = $request->request->getBoolean('fullScan', false);

        $this->validator->validate($fileName);

        if ($pattern === '') {
            return new JsonResponse(['error' => 'Pattern is required'], 400);
        }

        $readStart = microtime(true);
        $content   = $this->reader->readGrep($fileName, $pattern, $lines, $fullScan);
        $readMs    = round((microtime(true) - $readStart) * 1000, 1);

        return new JsonResponse([
            'content'   => $content,
            'lineCount' => substr_count($content, "\n") + 1,
            'readMs'    => $readMs,
            'mode'      => $fullScan ? 'grep (full file)' : 'grep (last 50 MB)',
            'loadedAt'  => date('H:i:s'),
        ]);
    }

    #[Route('/multi-tail', name: 'multi_tail', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function multiTail(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileNames = (array) $request->request->all('files');
        $offsets   = (array) $request->request->all('offsets');

        foreach ($fileNames as $fileName) {
            $this->validator->validate((string) $fileName);
        }

        return new JsonResponse($this->reader->readMultiFromOffset($fileNames, $offsets));
    }

    #[Route('/multi-grep', name: 'multi_grep', methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function multiGrep(Request $request): JsonResponse
    {
        if (!$this->isEnabled()) {
            return new JsonResponse(['error' => $this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY)], 403);
        }

        $fileNames    = (array) $request->request->all('files');
        $pattern      = trim((string) $request->request->get('pattern', ''));
        $limitPerFile = max(10, min(2000, (int) $request->request->get('limitPerFile', 500)));
        $fullScan     = $request->request->getBoolean('fullScan', false);

        foreach ($fileNames as $fileName) {
            $this->validator->validate((string) $fileName);
        }

        if ($pattern === '') {
            return new JsonResponse(['error' => 'Pattern is required'], 400);
        }

        return new JsonResponse($this->reader->readMultiGrep($fileNames, $pattern, $limitPerFile, $fullScan));
    }

    #[Route('/download/{fileName}', name: 'download', requirements: ['fileName' => '.+'], methods: ['GET'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function download(string $fileName): Response
    {
        if (!$this->isEnabled()) {
            throw $this->createAccessDeniedException($this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY));
        }

        try {
            $this->validator->validate($fileName);
            $fullPath = $this->reader->getFullPath($fileName);

            if (!file_exists($fullPath) || !is_readable($fullPath)) {
                $message = $this->translator->trans(self::FILE_ERROR_MESSAGE_KEY, ['%s' => $fileName]);
                throw new \RuntimeException($message); // NOSONAR - S112: Adequate for file system errors
            }

            $response = new BinaryFileResponse($fullPath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
            return $response;
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('genaker_log_viewer_index');
        }
    }

    #[Route('/delete/{fileName}', name: 'delete', requirements: ['fileName' => '.+'], methods: ['POST'])]
    #[AclAncestor('genaker_log_viewer_index')]
    public function delete(string $fileName, Request $request): Response
    {
        if (!$this->isEnabled()) {
            throw $this->createAccessDeniedException($this->translator->trans(self::LOG_VIEWER_DISABLED_MESSAGE_KEY));
        }

        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException($this->translator->trans('oro.security.error.invalid_csrf_token'));
        }

        try {
            $this->validator->validate($fileName);
            $fullPath = $this->reader->getFullPath($fileName);

            if (!file_exists($fullPath)) {
                $message = $this->translator->trans(self::FILE_ERROR_MESSAGE_KEY, ['%s' => $fileName]);
                throw new \RuntimeException($message); // NOSONAR - S112: Adequate for file system errors
            }

            unlink($fullPath);
            $successMsg = $this->translator->trans(self::DELETE_SUCCESS_MESSAGE_KEY, ['%s' => $fileName]);
            $this->addFlash('success', $successMsg);
        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('genaker.log_viewer.delete_error', ['%s' => $fileName]));
        }

        return $this->redirectToRoute('genaker_log_viewer_index');
    }

    private function isEnabled(): bool
    {
        $enabled = $this->configManager->get('genaker_log_viewer.enabled');

        // Default to enabled in dev environment if not explicitly configured
        if ($enabled === null) {
            $enabled = $this->environment === 'dev';
        }

        return (bool) $enabled;
    }
}
