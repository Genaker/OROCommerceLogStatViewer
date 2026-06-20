<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit;

use Genaker\Bundle\ComiVoyager\Core\ComiVoyager;
use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end check using real-world coordinates of major US cities (the
 * kind of stop list a delivery-route feature would actually see): the
 * shortest route ComiVoyager returns is compared against an independent
 * brute-force search over every possible visiting order, computed in this
 * test rather than reused from the solver. If the production pipeline ever
 * regresses to a non-optimal tour for an EXACT_LIMIT-sized input, this test
 * fails even though the brute-force oracle and the production code share
 * nothing but {@see HaversineDistanceMatrixProvider}.
 *
 * @covers \Genaker\Bundle\ComiVoyager\Core\ComiVoyager
 */
final class RealWorldRoutingTest extends TestCase
{
    /**
     * @return Address[]
     */
    private function usCities(): array
    {
        return [
            new Address('New York, NY', new Coordinate(40.7128, -74.0060)),
            new Address('Los Angeles, CA', new Coordinate(34.0522, -118.2437)),
            new Address('Chicago, IL', new Coordinate(41.8781, -87.6298)),
            new Address('Houston, TX', new Coordinate(29.7604, -95.3698)),
            new Address('Phoenix, AZ', new Coordinate(33.4484, -112.0740)),
            new Address('Philadelphia, PA', new Coordinate(39.9526, -75.1652)),
            new Address('San Antonio, TX', new Coordinate(29.4241, -98.4936)),
            new Address('San Diego, CA', new Coordinate(32.7157, -117.1611)),
            new Address('Dallas, TX', new Coordinate(32.7767, -96.7970)),
        ];
    }

    /**
     * Length of the closed loop `$tour[0] -> $tour[1] -> ... -> $tour[n-1]
     * -> $tour[0]`.
     *
     * @param int[] $tour
     */
    private function closedTourDistance(DistanceMatrix $matrix, array $tour): float
    {
        $size = count($tour);
        $distance = 0.0;

        for ($i = 0; $i < $size; $i++) {
            $distance += $matrix->distanceBetween($tour[$i], $tour[($i + 1) % $size]);
        }

        return $distance;
    }

    /**
     * Length of the open path `$tour[0] -> $tour[1] -> ... -> $tour[n-1]`.
     *
     * @param int[] $tour
     */
    private function openTourDistance(DistanceMatrix $matrix, array $tour): float
    {
        $distance = 0.0;

        for ($i = 0; $i < count($tour) - 1; $i++) {
            $distance += $matrix->distanceBetween($tour[$i], $tour[$i + 1]);
        }

        return $distance;
    }

    /**
     * Every ordering of `$items`, generated lazily so n! permutations are
     * never all held in memory at once.
     *
     * @param int[] $items
     * @return iterable<int[]>
     */
    private static function permutations(array $items): iterable
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $position => $item) {
            $remaining = $items;
            unset($remaining[$position]);

            foreach (self::permutations(array_values($remaining)) as $permutation) {
                yield [$item, ...$permutation];
            }
        }
    }

    /**
     * Closed-loop scenario: a delivery truck leaves the New York depot,
     * visits the other 8 cities, and returns. Brute-forces all 8! = 40,320
     * orderings of the non-depot cities to find the true shortest loop.
     */
    public function testOptimizeFindsTrueShortestClosedLoopForRealCities(): void
    {
        $cities = $this->usCities();
        $matrix = (new HaversineDistanceMatrixProvider())->build(
            array_map(static fn (Address $address) => $address->coordinate, $cities)
        );

        $bestDistance = null;

        foreach (self::permutations(range(1, count($cities) - 1)) as $rest) {
            $distance = $this->closedTourDistance($matrix, [0, ...$rest]);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
            }
        }

        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());
        $result = $engine->optimize($cities, routes: 1, options: new SolveOptions(returnToStart: true, depotIndex: 0));
        $best = $result->routes[0];

        self::assertEqualsWithDelta($bestDistance, $best->totalDistanceKm, 1e-6);
        self::assertCount(count($cities) + 1, $best->stops);
        self::assertSame('New York, NY', $best->stops[0]->address->label);
        self::assertSame('New York, NY', $best->stops[count($cities)]->address->label);

        $visited = array_map(static fn ($stop) => $stop->address->label, \array_slice($best->stops, 0, count($cities)));
        sort($visited);
        $expected = array_map(static fn (Address $address) => $address->label, $cities);
        sort($expected);
        self::assertSame($expected, $visited);
    }

    /**
     * Open-path scenario (no return to a depot): brute-forces all 8! =
     * 40,320 orderings of 8 real cities to find the true shortest path.
     */
    public function testOptimizeFindsTrueShortestOpenPathForRealCities(): void
    {
        $cities = \array_slice($this->usCities(), 0, 8);
        $matrix = (new HaversineDistanceMatrixProvider())->build(
            array_map(static fn (Address $address) => $address->coordinate, $cities)
        );

        $bestDistance = null;

        foreach (self::permutations(range(0, count($cities) - 1)) as $tour) {
            $distance = $this->openTourDistance($matrix, $tour);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
            }
        }

        $engine = new ComiVoyager(new HaversineDistanceMatrixProvider());
        $result = $engine->optimize($cities, routes: 1, options: new SolveOptions());
        $best = $result->routes[0];

        self::assertEqualsWithDelta($bestDistance, $best->totalDistanceKm, 1e-6);
        self::assertCount(count($cities), $best->stops);

        $visited = array_map(static fn ($stop) => $stop->address->label, $best->stops);
        sort($visited);
        $expected = array_map(static fn (Address $address) => $address->label, $cities);
        sort($expected);
        self::assertSame($expected, $visited);
    }
}
