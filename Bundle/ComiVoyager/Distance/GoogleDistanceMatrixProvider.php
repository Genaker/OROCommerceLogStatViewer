<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Distance;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Road-distance provider backed by the Google Distance Matrix API. Supports
 * a single batch of up to {@see self::MAX_POINTS} points per request.
 *
 * Full write-up (request/response format, the 25-point limit and why it
 * isn't batched, two-level status checking, billing/quota considerations):
 * {@see ../doc/distance-algorithms/GOOGLE.md}.
 * Setup (Google Cloud project, billing, API key): {@see ../doc/INSTALLATION.md} section 4.
 */
final class GoogleDistanceMatrixProvider implements DistanceMatrixProviderInterface
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    /**
     * Hard ceiling on points per request. This is a conservative,
     * single-check approximation of Google's documented per-dimension
     * (origins/destinations) limits for the Distance Matrix API. Exceeding
     * it fails fast (before any HTTP call) rather than letting Google
     * reject the request.
     */
    private const MAX_POINTS = 25;

    private const METERS_PER_KM = 1000.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Builds the full N x N matrix of driving distances (in km) via a
     * single Google Distance Matrix request, using the same coordinate
     * list as both `origins` and `destinations` (an "all pairs" query).
     *
     * @param Coordinate[] $coordinates
     */
    public function build(array $coordinates): DistanceMatrix
    {
        $size = count($coordinates);
        if ($size < 2) {
            // Degenerate input: trivial all-zero matrix, no HTTP call.
            return new DistanceMatrix(array_fill(0, $size, array_fill(0, $size, 0.0)));
        }

        if ($size > self::MAX_POINTS) {
            // Fail fast: a >25-point matrix would exceed Google's API
            // limits. Batching into multiple requests is intentionally not
            // implemented (see GOOGLE.md §3) — callers needing more points
            // should use the 'osrm' provider instead, which has no limit.
            throw new DistanceProviderUnavailableException(sprintf(
                'Google Distance Matrix provider supports at most %d points per request, %d given.',
                self::MAX_POINTS,
                $size
            ));
        }

        $apiKey = (string) $this->configManager->get('genaker_comi_voyager.google_api_key');
        if ($apiKey === '') {
            // Fail fast with a clear, actionable message rather than
            // letting Google return an opaque authentication error.
            throw new DistanceProviderUnavailableException('Google Distance Matrix provider requires an API key.');
        }

        // Google's API uses "lat,lng" order — the OPPOSITE of OSRM's
        // "lng,lat". The same point list is used for both origins and
        // destinations so the response covers every (i, j) pair in one call.
        $points = implode('|', array_map(
            static fn (Coordinate $coordinate) => sprintf('%F,%F', $coordinate->lat, $coordinate->lng),
            $coordinates
        ));

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => [
                    'origins' => $points,
                    'destinations' => $points,
                    'key' => $apiKey,
                ],
                'timeout' => 15,
            ]);

            $data = $response->toArray();
            if (($data['status'] ?? null) !== 'OK' || empty($data['rows'])) {
                // Top-level status covers the request as a whole (e.g.
                // OVER_QUERY_LIMIT, REQUEST_DENIED, INVALID_REQUEST). Any
                // non-OK value means nothing in `rows` can be trusted.
                throw new DistanceProviderUnavailableException(sprintf(
                    'Google Distance Matrix request failed with status "%s".',
                    $data['status'] ?? 'UNKNOWN'
                ));
            }

            $matrix = [];
            foreach ($data['rows'] as $i => $row) {
                foreach ($row['elements'] as $j => $element) {
                    if ($i === $j) {
                        $matrix[$i][$j] = 0.0;
                        continue;
                    }

                    if (($element['status'] ?? null) !== 'OK') {
                        // Per-element status: even when the overall request
                        // succeeds, an individual origin/destination pair
                        // can fail independently (most commonly
                        // ZERO_RESULTS — no route exists, e.g. islands with
                        // no bridge/ferry in Google's data). Treated as
                        // fatal for the whole matrix: a DistanceMatrix with
                        // a missing cell would break the solver's
                        // assumption that every cell has a valid distance.
                        throw new DistanceProviderUnavailableException(sprintf(
                            'Google Distance Matrix could not resolve the distance between point %d and %d.',
                            $i,
                            $j
                        ));
                    }

                    // distance.value is in meters; convert to km.
                    $matrix[$i][$j] = ((float) $element['distance']['value']) / self::METERS_PER_KM;
                }
            }

            return new DistanceMatrix($matrix);
        } catch (TransportExceptionInterface|ServerExceptionInterface|ClientExceptionInterface $exception) {
            // Network failure, timeout, or HTTP 4xx/5xx — log for
            // diagnostics and surface as a provider-level failure (-> HTTP
            // 422 in RouteOptimizationController).
            $this->logger->error('GoogleDistanceMatrixProvider error', ['error' => $exception->getMessage()]);

            throw new DistanceProviderUnavailableException(
                'Google Distance Matrix provider is unavailable: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Identifier used in configuration (`genaker_comi_voyager.distance_provider`)
     * and the `method` field of the HTTP API to select this provider.
     */
    public function getName(): string
    {
        return 'google';
    }
}
