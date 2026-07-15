<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Oro\Component\Config\CumulativeResourceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects the AI agent's general (non-tool-specific) system-prompt
 * guidelines from every registered bundle's
 * Resources/config/oro/oro_ai_guidelines.yml, so any bundle can extend the
 * agent's behavior without editing OroAiAgent directly — just by dropping a
 * YAML file with the same shape. Mirrors DocFilesRagProvider's bundle
 * scanning pattern.
 *
 * Tool-specific guidance ("when to use tool X") belongs on the tool's own
 * ToolDefinition::description instead — this provider is only for rules
 * that apply across the whole agent, not to one tool.
 */
final class GuidelineProvider implements GuidelineProviderInterface
{
    private const string CONFIG_FILE = 'Resources/config/oro/oro_ai_guidelines.yml';
    private const string ROOT_NODE = 'oro_ai';

    /** @return string[] */
    public function getGuidelines(): array
    {
        $guidelines = [];

        foreach ($this->findConfigFiles() as $file) {
            $parsed = Yaml::parseFile($file);
            $items = $parsed[self::ROOT_NODE]['guidelines'] ?? [];

            foreach ($items as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $guidelines[] = trim($item);
                }
            }
        }

        return $guidelines;
    }

    /** @return string[] */
    private function findConfigFiles(): array
    {
        $manager = CumulativeResourceManager::getInstance();
        $files = [];

        foreach ($manager->getBundles() as $bundleClass) {
            $file = rtrim($manager->getBundleDir($bundleClass), '/') . '/' . self::CONFIG_FILE;
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }
}
