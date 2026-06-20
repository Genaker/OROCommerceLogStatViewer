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
 * Road-distance provider backed by the OSRM `/table` service.
 *
 * Full write-up (how the `/table` service works, request/response format,
 * worked example, operational considerations for self-hosting):
 * {@see ../doc/distance-algorithms/OSRM.md}.
 * Setup: {@see ../doc/INSTALLATION.md} section 3.
 */
final class OsrmDistanceMatrixProvider implements DistanceMatrixProviderInterface
{
    /**
     * OSRM project's free public demo server. Suitable for testing only —
     * shared globally, rate-limited, no SLA. See INSTALLATION.md §3 for
     * self-hosting.
     */
    private const DEFAULT_BASE_URL = 'https://router.project-osrm.org';

    private const METERS_PER_KM = 1000.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Builds the full N x N matrix of driving distances (in km) by calling
     * OSRM's Table service once with all coordinates. The Table service
     * runs a many-to-many shortest-path query against OSRM's pre-processed
     * contraction-hierarchy/MLD graph, returning the entire matrix in a
     * single response regardless of N.
     *
     * @param Coordinate[] $coordinates
     */
    public function build(array $coordinates): DistanceMatrix
    {
        $size = count($coordinates);
        if ($size < 2) {
            // Degenerate input (0 or 1 points): return a trivial all-zero
            // matrix without making an HTTP call. In practice the solver
            // always requires >= 2 addresses, so this is purely defensive.
            return new DistanceMatrix(array_fill(0, $size, array_fill(0, $size, 0.0)));
        }

        // OSRM (and GeoJSON) expect coordinates as "lng,lat" — the OPPOSITE
        // of the Coordinate model's lat/lng field order and of Google's
        // "lat,lng" convention. Getting this backwards is the most common
        // OSRM integration bug. '%F' formats with full precision and a
        // locale-independent '.' decimal separator (a locale using ','
        // would otherwise produce an invalid URL).
        $coordinatesParam = implode(';', array_map(
            static fn (Coordinate $coordinate) => sprintf('%F,%F', $coordinate->lng, $coordinate->lat),
            $coordinates
        ));

        // Allow overriding the OSRM instance via system configuration
        // (e.g. to point at a self-hosted server); fall back to the public
        // demo server. Trailing slash is stripped so the sprintf below
        // never produces a double slash.
        $baseUrl = rtrim(
            (string) ($this->configManager->get('genaker_comi_voyager.osrm_base_url') ?: self::DEFAULT_BASE_URL),
            '/'
        );

        try {
            // 'driving' is the only routing profile exposed by this bundle.
            // 'annotations=distance' requests distances only — OSRM can
            // also return 'duration'/'speed', not used here. 15s timeout
            // allows for cold starts on a self-hosted instance.
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/table/v1/driving/%s', $baseUrl, $coordinatesParam),
                [
                    'query' => ['annotations' => 'distance'],
                    'timeout' => 15,
                ]
            );

            $data = $response->toArray();
            if (($data['code'] ?? null) !== 'Ok' || empty($data['distances'])) {
                // 'code' !== 'Ok' covers OSRM-level errors such as
                // "NoRoute" (no path between two points) or "InvalidQuery".
                throw new DistanceProviderUnavailableException('OSRM table request did not return distances.');
            }

            // OSRM returns distances in meters as a 2D array
            // distances[i][j]; convert to km and force the diagonal to
            // 0.0 for consistency with the other providers (defensive,
            // in case OSRM ever returns a tiny non-zero self-distance due
            // to coordinate snapping).
            // OSRM returns null for unroutable pairs (e.g. islands with no
            // ferry); this is a hard provider failure, not a routing option.
            $matrix = [];
            foreach ($data['distances'] as $i => $row) {
                foreach ($row as $j => $meters) {
                    if ($i !== $j && !is_numeric($meters)) {
                        throw new DistanceProviderUnavailableException(
                            sprintf('OSRM returned non-numeric distance for pair [%d, %d]: %s',
                                $i,
                                $j,
                                $meters === null ? 'null (unroutable)' : var_export($meters, true)
                            )
                        );
                    }
                    $matrix[$i][$j] = $i === $j ? 0.0 : ((float) $meters) / self::METERS_PER_KM;
                }
            }

            return new DistanceMatrix($matrix);
        } catch (TransportExceptionInterface|ServerExceptionInterface|ClientExceptionInterface $exception) {
            // Network failure, DNS error, timeout, or HTTP 4xx/5xx from
            // OSRM — log for diagnostics and surface as a provider-level
            // failure (-> HTTP 422 in RouteOptimizationController).
            $this->logger->error('OsrmDistanceMatrixProvider error', ['error' => $exception->getMessage()]);

            throw new DistanceProviderUnavailableException(
                'OSRM distance provider is unavailable: ' . $exception->getMessage(),
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
        return 'osrm';
    }
}
