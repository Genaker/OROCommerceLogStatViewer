<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Geocoder;

use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Free, default geocoder backed by the OpenStreetMap Nominatim search API.
 */
final class NominatimGeocoder implements GeocoderInterface
{
    private const BASE_URL = 'https://nominatim.openstreetmap.org/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function geocode(string $address): ?Coordinate
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                ],
                'headers' => [
                    'User-Agent' => 'ComiVoyager/1.0',
                ],
                'timeout' => 10,
            ]);

            $results = $response->toArray();
            if (empty($results[0]['lat']) || empty($results[0]['lon'])) {
                return null;
            }

            return new Coordinate((float) $results[0]['lat'], (float) $results[0]['lon']);
        } catch (TransportExceptionInterface|ServerExceptionInterface|ClientExceptionInterface $exception) {
            $this->logger->error('NominatimGeocoder error', [
                'address' => $address,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    public function getName(): string
    {
        return 'nominatim';
    }
}
