<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * One candidate visiting order, with its stops, legs and summary statistics.
 */
final class Route
{
    /**
     * @param Stop[] $stops
     * @param Leg[] $legs
     */
    public function __construct(
        public readonly array $stops,
        public readonly array $legs,
        public readonly float $totalDistanceKm,
        public int $rank = 0,
        public bool $isShortest = false,
        public float $deltaFromBestKm = 0.0,
    ) {
    }

    public function totalStops(): int
    {
        return count($this->stops);
    }

    public function averageLegKm(): float
    {
        if ($this->legs === []) {
            return 0.0;
        }

        return $this->totalDistanceKm / count($this->legs);
    }

    public function longestLegKm(): float
    {
        $longest = 0.0;

        foreach ($this->legs as $leg) {
            $longest = max($longest, $leg->distanceKm);
        }

        return $longest;
    }

    /**
     * @return array{
     *     rank: int,
     *     isShortest: bool,
     *     totalDistanceKm: float,
     *     totalStops: int,
     *     averageLegKm: float,
     *     longestLegKm: float,
     *     deltaFromBestKm: float,
     *     stops: array<int, array<string, mixed>>,
     *     legs: array<int, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'isShortest' => $this->isShortest,
            'totalDistanceKm' => $this->totalDistanceKm,
            'totalStops' => $this->totalStops(),
            'averageLegKm' => $this->averageLegKm(),
            'longestLegKm' => $this->longestLegKm(),
            'deltaFromBestKm' => $this->deltaFromBestKm,
            'stops' => array_map(static fn (Stop $stop): array => $stop->toArray(), $this->stops),
            'legs' => array_map(static fn (Leg $leg): array => $leg->toArray(), $this->legs),
        ];
    }
}
