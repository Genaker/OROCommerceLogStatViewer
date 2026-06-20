<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Distance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Great-circle distance computed by PostGIS's `ST_DistanceSphere`, via a
 * dedicated (non-default) Postgres/PostGIS connection.
 *
 * Full write-up (the `comivoyager_postgis` connection, SQL mechanics,
 * symmetric-matrix optimization, performance/operational considerations):
 * {@see ../doc/distance-algorithms/POSTGIS.md}.
 * Setup (Docker Compose service or bare-metal Postgres+PostGIS):
 * {@see ../doc/INSTALLATION.md} section 5.
 */
final class PostgisDistanceMatrixProvider implements DistanceMatrixProviderInterface
{
    private const METERS_PER_KM = 1000.0;

    /**
     * @param Connection $connection DBAL-only connection to the dedicated
     *        `comivoyager_postgis` database (service id
     *        `doctrine.dbal.comivoyager_postgis_connection`), registered
     *        by this bundle's DependencyInjection extension via
     *        `prepend()`/`env(ORO_COMIVOYAGER_POSTGIS_DSN)` — no app-level
     *        config changes are required.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Builds the full N x N matrix of great-circle distances (in km) using
     * PostGIS's `ST_DistanceSphere` — same accuracy as
     * HaversineDistanceMatrixProvider, just computed in SQL.
     *
     * All pairwise distances are fetched in a **single round trip**: the
     * input coordinates are inlined as a `VALUES` list, cross-joined with
     * itself, and filtered to the upper triangle (`p1.idx < p2.idx`) so
     * `ST_DistanceSphere` is evaluated exactly once per unordered pair —
     * the result is then mirrored into the lower triangle in PHP.
     *
     * @param Coordinate[] $coordinates
     */
    public function build(array $coordinates): DistanceMatrix
    {
        $size = count($coordinates);
        // Pre-fill with zeros: the diagonal (i === i) stays 0.0 and is
        // never queried.
        $matrix = array_fill(0, $size, array_fill(0, $size, 0.0));

        if ($size < 2) {
            // Degenerate input (0 or 1 points): no pairs to compute, and a
            // single-row VALUES list cross-joined with itself yields no
            // rows under `p1.idx < p2.idx` anyway.
            return new DistanceMatrix($matrix);
        }

        try {
            foreach ($this->fetchPairwiseDistances($coordinates) as ['i' => $i, 'j' => $j, 'meters' => $meters]) {
                $distanceKm = ((float) $meters) / self::METERS_PER_KM;
                // Mirror the result into both (i,j) and (j,i) — the
                // distance is symmetric, so one row serves both cells.
                $matrix[$i][$j] = $distanceKm;
                $matrix[$j][$i] = $distanceKm;
            }
        } catch (DbalException $exception) {
            // Any DB-level failure (connection refused, auth failure,
            // timeout) aborts the whole matrix build. Logged for
            // diagnostics, then surfaced as a provider-level failure (->
            // HTTP 422 in RouteOptimizationController).
            $this->logger->error('PostgisDistanceMatrixProvider error', ['error' => $exception->getMessage()]);

            throw new DistanceProviderUnavailableException(
                'PostGIS distance provider is unavailable: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return new DistanceMatrix($matrix);
    }

    /**
     * Identifier used in configuration (`genaker_comi_voyager.distance_provider`)
     * and the `method` field of the HTTP API to select this provider.
     */
    public function getName(): string
    {
        return 'postgis';
    }

    /**
     * Runs `ST_DistanceSphere` for every unordered pair of input
     * coordinates in a single query and returns the raw rows
     * (`i`, `j`, `meters`), with `i < j`.
     *
     * Each coordinate is inlined as a `(idx, lng, lat)` row in a `VALUES`
     * list, which is cross-joined with itself; `p1.idx < p2.idx` keeps only
     * the upper triangle, exploiting `ST_DistanceSphere(A, B) ===
     * ST_DistanceSphere(B, A)` to evaluate each pair exactly once.
     *
     * `ST_MakePoint(x, y)` follows PostGIS's (longitude, latitude) /
     * Cartesian (x, y) convention — note `lng` is passed first, matching
     * OSRM's convention but the opposite of Google's `lat,lng`.
     * `ST_DistanceSphere` computes the distance on a sphere (the same
     * model as the haversine formula), constructing both points on the
     * fly from parameters — no table read, no spatial index, and no
     * bundle-owned schema is needed in the PostGIS database. Coordinates
     * are passed via DBAL named-parameter binding, so there's no SQL
     * injection risk despite the values originating from user input.
     *
     * @param Coordinate[] $coordinates
     * @return list<array{i: int, j: int, meters: float|string}>
     */
    private function fetchPairwiseDistances(array $coordinates): array
    {
        $rows = [];
        $params = [];

        foreach ($coordinates as $index => $coordinate) {
            $rows[] = sprintf('(%d, :lng%1$d, :lat%1$d)', $index);
            $params['lng' . $index] = $coordinate->lng;
            $params['lat' . $index] = $coordinate->lat;
        }

        $values = implode(', ', $rows);

        $sql = <<<SQL
            SELECT p1.idx AS i, p2.idx AS j,
                   ST_DistanceSphere(ST_MakePoint(p1.lng, p1.lat), ST_MakePoint(p2.lng, p2.lat)) AS meters
            FROM (VALUES {$values}) AS p1(idx, lng, lat)
            CROSS JOIN (VALUES {$values}) AS p2(idx, lng, lat)
            WHERE p1.idx < p2.idx
            SQL;

        return array_map(
            static fn (array $row): array => ['i' => (int) $row['i'], 'j' => (int) $row['j'], 'meters' => $row['meters']],
            $this->connection->fetchAllAssociative($sql, $params)
        );
    }
}
