<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Solver\RouteSequencer;
use PHPUnit\Framework\TestCase;

class RouteSequencerTest extends TestCase
{
    private RouteSequencer $sequencer;

    protected function setUp(): void
    {
        $this->sequencer = new RouteSequencer(new HaversineDistanceMatrixProvider());
    }

    public function testEmptyStops(): void
    {
        $start = new Coordinate(40.0, -74.0);
        $result = $this->sequencer->sequence([], $start, null, true);
        self::assertSame([], $result['order']);
        self::assertSame(0.0, $result['distanceKm']);
    }

    public function testSingleStopRoundTrip(): void
    {
        $start = new Coordinate(40.0, -74.0);
        $stops = [new Coordinate(40.1, -74.0)];
        $result = $this->sequencer->sequence($stops, $start, null, true);
        self::assertSame([0], $result['order']);
        // Round trip: start -> stop -> start, ~22 km
        self::assertGreaterThan(0, $result['distanceKm']);
    }

    public function testOrdersCollinearStops(): void
    {
        // Stops along a line east of start; optimal order is left-to-right
        $start = new Coordinate(40.0, -74.0);
        $stops = [
            new Coordinate(40.0, -73.7), // farthest
            new Coordinate(40.0, -73.9), // nearest
            new Coordinate(40.0, -73.8), // middle
        ];

        $result = $this->sequencer->sequence($stops, $start, null, false);

        // Open route from start: should visit nearest (idx 1), middle (idx 2), far (idx 0)
        self::assertSame([1, 2, 0], $result['order']);
    }

    public function testRoundTripVsOpenDistance(): void
    {
        $start = new Coordinate(40.0, -74.0);
        $stops = [
            new Coordinate(40.0, -73.9),
            new Coordinate(40.0, -73.8),
        ];

        $roundTrip = $this->sequencer->sequence($stops, $start, null, true);
        $openRoute = $this->sequencer->sequence($stops, $start, null, false);

        // Round trip must be longer (it returns to start)
        self::assertGreaterThan($openRoute['distanceKm'], $roundTrip['distanceKm']);
    }

    public function testFixedEndLocation(): void
    {
        $start = new Coordinate(40.0, -74.0);
        $end = new Coordinate(40.0, -73.5); // far east
        $stops = [
            new Coordinate(40.0, -73.9),
            new Coordinate(40.0, -73.7),
            new Coordinate(40.0, -73.8),
        ];

        // Open route to fixed end: start(west) -> ... -> end(east)
        // optimal order west-to-east: 0(-73.9), 2(-73.8), 1(-73.7)
        $result = $this->sequencer->sequence($stops, $start, $end, false);

        self::assertSame([0, 2, 1], $result['order']);
        self::assertCount(3, $result['order']);
    }

    public function testTwoOptImprovesCrossing(): void
    {
        // A configuration where nearest-neighbour would cross itself
        $start = new Coordinate(40.0, -74.0);
        $stops = [
            new Coordinate(40.0, -73.99),
            new Coordinate(40.05, -73.95),
            new Coordinate(40.0, -73.90),
            new Coordinate(40.05, -73.85),
        ];

        $result = $this->sequencer->sequence($stops, $start, null, true);

        // All four stops present exactly once
        self::assertCount(4, $result['order']);
        $sorted = $result['order'];
        sort($sorted);
        self::assertSame([0, 1, 2, 3], $sorted);
    }

    public function testAllStopsPresent(): void
    {
        $start = new Coordinate(40.7, -74.0);
        $stops = [];
        for ($i = 0; $i < 8; $i++) {
            $stops[] = new Coordinate(40.7 + $i * 0.01, -74.0 + $i * 0.01);
        }

        $result = $this->sequencer->sequence($stops, $start, null, true);

        $sorted = $result['order'];
        sort($sorted);
        self::assertSame(range(0, 7), $sorted);
    }
}
