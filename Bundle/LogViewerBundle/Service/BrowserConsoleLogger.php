<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

/**
 * Collects backend log entries during a request and makes them available
 * for injection into the browser developer console.
 *
 * Usage from any service:
 *   $this->consoleLogger->log('Pegging API response', $responseData);
 *   $this->consoleLogger->warn('Slow query detected', ['ms' => 1200]);
 *   $this->consoleLogger->error('MuleSoft timeout', $exception->getMessage());
 *   $this->consoleLogger->table('Order lines', $rows);
 *   $this->consoleLogger->group('CartService');
 *   $this->consoleLogger->groupEnd();
 */
class BrowserConsoleLogger
{
    private const int DEFAULT_MAX_ENTRIES = 200;
    private const int DEFAULT_MAX_PAYLOAD_BYTES = 1048576; // 1 MB

    private array $entries = [];
    private bool $enabled = true;
    private int $maxEntries = self::DEFAULT_MAX_ENTRIES;
    private int $maxPayloadBytes = self::DEFAULT_MAX_PAYLOAD_BYTES;
    private int $payloadBytes = 0;
    private bool $truncated = false;

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setMaxEntries(int $maxEntries): void
    {
        $this->maxEntries = max(1, $maxEntries);
    }

    public function getMaxEntries(): int
    {
        return $this->maxEntries;
    }

    public function setMaxPayloadBytes(int $maxPayloadBytes): void
    {
        $this->maxPayloadBytes = max(1024, $maxPayloadBytes);
    }

    public function getMaxPayloadBytes(): int
    {
        return $this->maxPayloadBytes;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function log(string $label, mixed $data = null): void
    {
        $this->add('log', $label, $data);
    }

    public function info(string $label, mixed $data = null): void
    {
        $this->add('info', $label, $data);
    }

    public function warn(string $label, mixed $data = null): void
    {
        $this->add('warn', $label, $data);
    }

    public function error(string $label, mixed $data = null): void
    {
        $this->add('error', $label, $data);
    }

    public function debug(string $label, mixed $data = null): void
    {
        $this->add('debug', $label, $data);
    }

    public function table(string $label, array $data): void
    {
        $this->add('table', $label, $data);
    }

    public function group(string $label, bool $collapsed = false): void
    {
        if (!$this->enabled || !$this->canAdd()) {
            return;
        }

        $this->entries[] = [
            'method' => $collapsed ? 'groupCollapsed' : 'group',
            'label'  => $label,
            'data'   => null,
            'time'   => microtime(true),
        ];
    }

    public function groupEnd(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->entries[] = [
            'method' => 'groupEnd',
            'label'  => null,
            'data'   => null,
            'time'   => microtime(true),
        ];
    }

    /** @return array<int, array{method: string, label: ?string, data: mixed, time: float}> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function hasEntries(): bool
    {
        return $this->entries !== [];
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->payloadBytes = 0;
        $this->truncated = false;
    }

    /**
     * Renders all collected entries as a self-contained <script> block
     * safe for injection before </body>.
     *
     * @param string|null $nonce CSP nonce to add to the script tag
     */
    public function renderScript(?string $nonce = null): string
    {
        if (!$this->enabled || $this->entries === []) {
            return '';
        }

        $nonceAttr = $nonce !== null
            ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5) . '"'
            : '';

        $js = "<script data-browser-console-logger{$nonceAttr}>\n(function(){\n";
        $js .= "var c=window.console;\nif(!c)return;\n";

        foreach ($this->entries as $entry) {
            $method = $entry['method'];

            if ($method === 'groupEnd') {
                $js .= "c.groupEnd();\n";
                continue;
            }

            if (in_array($method, ['group', 'groupCollapsed'], true)) {
                $js .= sprintf(
                    "c.%s(%s);\n",
                    $method,
                    json_encode('[PHP] ' . $entry['label'], JSON_THROW_ON_ERROR)
                );
                continue;
            }

            $label = '[PHP] ' . ($entry['label'] ?? '');

            if ($method === 'table' && is_array($entry['data'])) {
                $js .= sprintf(
                    "c.log(%s);\nc.table(%s);\n",
                    json_encode($label, JSON_THROW_ON_ERROR),
                    json_encode($entry['data'], JSON_HEX_TAG | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
                );
                continue;
            }

            if ($entry['data'] !== null) {
                $js .= sprintf(
                    "c.%s(%s,%s);\n",
                    $method,
                    json_encode($label, JSON_THROW_ON_ERROR),
                    json_encode($entry['data'], JSON_HEX_TAG | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
                );
            } else {
                $js .= sprintf(
                    "c.%s(%s);\n",
                    $method,
                    json_encode($label, JSON_THROW_ON_ERROR)
                );
            }
        }

        if ($this->truncated) {
            $js .= sprintf(
                "c.warn(%s);\n",
                json_encode(
                    sprintf(
                        '[PHP] BrowserConsoleLogger: output truncated (limit: %d entries / %d KB)',
                        $this->maxEntries,
                        (int) ($this->maxPayloadBytes / 1024)
                    ),
                    JSON_THROW_ON_ERROR
                )
            );
        }

        $js .= "})();\n</script>";

        return $js;
    }

    private function canAdd(): bool
    {
        if (count($this->entries) >= $this->maxEntries) {
            $this->truncated = true;

            return false;
        }

        if ($this->payloadBytes >= $this->maxPayloadBytes) {
            $this->truncated = true;

            return false;
        }

        return true;
    }

    private function add(string $method, string $label, mixed $data): void
    {
        if (!$this->enabled || !$this->canAdd()) {
            return;
        }

        $normalized = $this->normalize($data);
        $entrySize = strlen($label) + strlen((string) json_encode($normalized));
        $this->payloadBytes += $entrySize;

        $this->entries[] = [
            'method' => $method,
            'label'  => $label,
            'data'   => $normalized,
            'time'   => microtime(true),
        ];
    }

    private function normalize(mixed $data): mixed
    {
        if ($data === null || is_scalar($data)) {
            return $data;
        }

        if (is_array($data)) {
            return array_map(fn ($v) => $this->normalize($v), $data);
        }

        if ($data instanceof \Throwable) {
            return [
                'class'   => $data::class,
                'message' => $data->getMessage(),
                'code'    => $data->getCode(),
                'file'    => $data->getFile() . ':' . $data->getLine(),
            ];
        }

        if ($data instanceof \JsonSerializable) {
            return $data->jsonSerialize();
        }

        if (is_object($data) && method_exists($data, '__toString')) {
            return (string) $data;
        }

        if (is_object($data)) {
            return '[object ' . $data::class . ']';
        }

        return '[' . get_debug_type($data) . ']';
    }
}
