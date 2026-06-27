<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * A square matrix of pairwise distances (in kilometers) between addresses,
 * indexed in the same order as the addresses passed to the solver.
 *
 * Pure-math providers (haversine, vincenty, postgis) produce symmetric
 * matrices (distance(i,j) === distance(j,i)), but road-network providers
 * (osrm, google) generally do not — one-way streets, ramps, and turn
 * restrictions make A->B differ from B->A. Solvers that exploit symmetry
 * (reversal-invariant deltas, treating a reversed tour as a duplicate) must
 * check {@see self::isSymmetric()} first.
 */
final class DistanceMatrix
{
    /**
     * Tolerance (in km, = 1 mm) when classifying a matrix as symmetric.
     * Pure-math providers produce bit-identical mirrored values; genuine
     * road-network asymmetry is orders of magnitude larger than this.
     */
    private const SYMMETRY_EPSILON_KM = 1e-6;

    private ?bool $symmetric = null;

    /**
     * @param float[][] $distancesKm $distancesKm[$i][$j] is the distance from stop $i to stop $j.
     */
    public function __construct(
        private readonly array $distancesKm,
    ) {
    }

    public function size(): int
    {
        return count($this->distancesKm);
    }

    public function distanceBetween(int $from, int $to): float
    {
        return $this->distancesKm[$from][$to];
    }

    /**
     * Whether distance(i,j) === distance(j,i) for every pair (within
     * {@see self::SYMMETRY_EPSILON_KM}). Computed once, lazily — O(n²) on
     * first call, cached afterwards.
     */
    public function isSymmetric(): bool
    {
        return $this->symmetric ??= $this->computeSymmetry();
    }

    private function computeSymmetry(): bool
    {
        $size = count($this->distancesKm);

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i + 1; $j < $size; $j++) {
                if (abs($this->distancesKm[$i][$j] - $this->distancesKm[$j][$i]) > self::SYMMETRY_EPSILON_KM) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return float[][]
     */
    public function toArray(): array
    {
        return $this->distancesKm;
    }
}
