<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Service;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Backs the "Test Connection" buttons in System Configuration for the OSRM
 * base URL and Google API key fields, so a misconfigured value can be
 * caught from the admin screen instead of surfacing as a 422 at request
 * time (in {@see \Genaker\Bundle\ComiVoyager\Distance\OsrmDistanceMatrixProvider}
 * / {@see \Genaker\Bundle\ComiVoyager\Distance\GoogleDistanceMatrixProvider}).
 */
final class ConnectionTesterService
{
    /**
     * Two real-world points (Berlin) commonly used to smoke-test OSRM demo
     * servers — any OSRM instance with global or European coverage can
     * route between them.
     */
    private const OSRM_TEST_COORDINATES = '13.388860,52.517037;13.397634,52.529407';

    private const GOOGLE_GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly SymmetricCrypterInterface $crypter,
    ) {
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testOsrm(?string $baseUrl): array
    {
        $baseUrl = rtrim($this->resolveValue($baseUrl, 'genaker_comi_voyager.osrm_base_url'), '/');

        if ($baseUrl === '') {
            return ['success' => false, 'message' => 'No OSRM base URL configured.'];
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/table/v1/driving/%s', $baseUrl, self::OSRM_TEST_COORDINATES),
                ['query' => ['annotations' => 'distance'], 'timeout' => 10]
            );

            $data = $response->toArray(false);
            if (($data['code'] ?? null) === 'Ok') {
                return ['success' => true, 'message' => sprintf('Connected to %s successfully.', $baseUrl)];
            }

            return [
                'success' => false,
                'message' => sprintf('OSRM responded with code "%s".', (string) ($data['code'] ?? 'unknown')),
            ];
        } catch (TransportExceptionInterface|DecodingExceptionInterface $exception) {
            return ['success' => false, 'message' => 'Could not connect to OSRM: ' . $exception->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testGoogle(?string $apiKey): array
    {
        $apiKey = $this->resolveApiKey($apiKey);

        if ($apiKey === '') {
            return ['success' => false, 'message' => 'No Google API key configured.'];
        }

        try {
            $response = $this->httpClient->request('GET', self::GOOGLE_GEOCODE_URL, [
                'query' => ['address' => 'New York', 'key' => $apiKey],
                'timeout' => 10,
            ]);

            $data = $response->toArray(false);
            $status = (string) ($data['status'] ?? 'UNKNOWN');

            if ($status === 'OK') {
                return ['success' => true, 'message' => 'Google API key is valid.'];
            }

            return ['success' => false, 'message' => sprintf('Google API responded with status "%s".', $status)];
        } catch (TransportExceptionInterface|DecodingExceptionInterface $exception) {
            return ['success' => false, 'message' => 'Could not reach Google API: ' . $exception->getMessage()];
        }
    }

    /**
     * Falls back to the stored config value when the submitted value is
     * empty (the admin clicked "Test Connection" without typing anything).
     */
    private function resolveValue(?string $value, string $configKey): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return (string) $this->configManager->get($configKey);
    }

    /**
     * Resolves the API key to test: the freshly-typed value, or — if the
     * field still shows its encrypted placeholder (all "*") or is empty —
     * the currently stored, decrypted key.
     */
    private function resolveApiKey(?string $value): string
    {
        if ($value !== null && $value !== '' && !$this->isPlaceholder($value)) {
            return $value;
        }

        $stored = (string) $this->configManager->get('genaker_comi_voyager.google_api_key');

        return $stored !== '' ? $this->crypter->decryptData($stored) : '';
    }

    private function isPlaceholder(string $value): bool
    {
        return trim($value, '*') === '';
    }
}
