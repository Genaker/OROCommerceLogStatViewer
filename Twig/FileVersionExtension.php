<?php

namespace Genaker\Bundle\LogViewerBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides a file_version() Twig function that returns the mtime of a public asset file.
 * This allows individual CSS/JS files to auto-bust the browser cache on every save
 * without requiring a full webpack rebuild or manual build_version.txt bump.
 */
class FileVersionExtension extends AbstractExtension
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('file_version', $this->fileVersion(...)),
        ];
    }

    /**
     * Returns the Unix mtime of a file under public/, or 0 if not found.
     *
     * Usage: {{ file_version('bundles/genakerlogviewer/css/perf-dashboard.css') }}
     */
    public function fileVersion(string $publicPath): int
    {
        $absolutePath = $this->projectDir . '/public/' . ltrim($publicPath, '/');
        $mtime = @filemtime($absolutePath);

        return $mtime !== false ? $mtime : 0;
    }
}
