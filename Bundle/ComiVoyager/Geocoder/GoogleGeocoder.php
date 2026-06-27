<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Geocoder;

use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocoder backed by the Google Geocoding API. Requires
 * `genaker_comi_voyager.google_api_key` to be configured.
 */
final class GoogleGeocoder implements GeocoderInterface
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function geocode(string $address): ?Coordinate
    {
        $apiKey = (string) $this->configManager->get('genaker_comi_voyager.google_api_key');
        if ($apiKey === '') {
            $this->logger->warning('GoogleGeocoder: missing API key, skipping geocode', ['address' => $address]);

            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => [
                    'address' => $address,
                    'key' => $apiKey,
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            if (($data['status'] ?? null) !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
                $this->logger->warning('GoogleGeocoder: no result', [
                    'address' => $address,
                    'status' => $data['status'] ?? null,
                ]);

                return null;
            }

            $location = $data['results'][0]['geometry']['location'];

            return new Coordinate((float) $location['lat'], (float) $location['lng']);
        } catch (TransportExceptionInterface|ServerExceptionInterface|ClientExceptionInterface $exception) {
            $this->logger->error('GoogleGeocoder error', [
                'address' => $address,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    public function getName(): string
    {
        return 'google';
    }
}
