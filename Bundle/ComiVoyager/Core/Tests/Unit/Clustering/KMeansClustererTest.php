<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Clustering;

use Genaker\Bundle\ComiVoyager\Core\Clustering\KMeansClusterer;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use PHPUnit\Framework\TestCase;

class KMeansClustererTest extends TestCase
{
    private KMeansClusterer $clusterer;

    protected function setUp(): void
    {
        $this->clusterer = new KMeansClusterer();
    }

    public function testEmptyOrders(): void
    {
        self::assertSame([], $this->clusterer->cluster([], 3));
    }

    public function testZeroClusters(): void
    {
        $orders = [$this->order('A', 40.0, -74.0)];
        self::assertSame([], $this->clusterer->cluster($orders, 0));
    }

    public function testMoreClustersThanOrders(): void
    {
        $orders = [
            $this->order('A', 40.0, -74.0),
            $this->order('B', 41.0, -73.0),
        ];
        $result = $this->clusterer->cluster($orders, 5);
        self::assertCount(2, $result);
        self::assertSame('A', $result[0][0]->getId());
        self::assertSame('B', $result[1][0]->getId());
    }

    public function testTwoClustersGeographic(): void
    {
        // NYC area vs LA area
        $orders = [
            $this->order('NYC1', 40.71, -74.01),
            $this->order('NYC2', 40.73, -73.99),
            $this->order('NYC3', 40.75, -73.97),
            $this->order('LA1', 34.05, -118.24),
            $this->order('LA2', 34.07, -118.22),
            $this->order('LA3', 34.09, -118.20),
        ];

        $clusters = $this->clusterer->cluster($orders, 2);

        self::assertCount(2, $clusters);

        // Each cluster should have 3 orders
        $sizes = array_map('count', $clusters);
        sort($sizes);
        self::assertSame([3, 3], $sizes);

        // NYC orders should be in the same cluster
        $cluster0Ids = array_map(fn($o) => $o->getId(), $clusters[0]);
        $cluster1Ids = array_map(fn($o) => $o->getId(), $clusters[1]);

        $nycInCluster0 = count(array_intersect($cluster0Ids, ['NYC1', 'NYC2', 'NYC3']));
        $nycInCluster1 = count(array_intersect($cluster1Ids, ['NYC1', 'NYC2', 'NYC3']));

        // All NYC should be together (in one cluster, not split)
        self::assertTrue($nycInCluster0 === 3 || $nycInCluster1 === 3);
    }

    public function testThreeClusters(): void
    {
        $orders = [
            // North cluster
            $this->order('N1', 42.0, -74.0),
            $this->order('N2', 42.1, -73.9),
            // Central cluster
            $this->order('C1', 40.0, -74.0),
            $this->order('C2', 40.1, -73.9),
            // South cluster
            $this->order('S1', 38.0, -74.0),
            $this->order('S2', 38.1, -73.9),
        ];

        $clusters = $this->clusterer->cluster($orders, 3);
        self::assertCount(3, $clusters);

        $totalOrders = array_sum(array_map('count', $clusters));
        self::assertSame(6, $totalOrders);
    }

    public function testDepotSeedsCentroid(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00),
            $this->order('B', 41.50, -73.50),
        ];

        $clusters = $this->clusterer->cluster($orders, 2, $depot);
        self::assertCount(2, $clusters);
    }

    public function testSingleOrder(): void
    {
        $orders = [$this->order('A', 40.0, -74.0)];
        $clusters = $this->clusterer->cluster($orders, 1);
        self::assertCount(1, $clusters);
        self::assertCount(1, $clusters[0]);
    }

    public function testIdenticalCoordinates(): void
    {
        $orders = [
            $this->order('A', 40.0, -74.0),
            $this->order('B', 40.0, -74.0),
            $this->order('C', 40.0, -74.0),
        ];
        $clusters = $this->clusterer->cluster($orders, 2);
        $total = array_sum(array_map('count', $clusters));
        self::assertSame(3, $total);
    }

    public function testLargerDataset(): void
    {
        $orders = [];
        for ($i = 0; $i < 50; $i++) {
            $lat = 40.0 + ($i % 5) * 0.5;
            $lng = -74.0 + (int)($i / 5) * 0.5;
            $orders[] = $this->order("O$i", $lat, $lng);
        }

        $clusters = $this->clusterer->cluster($orders, 5);
        self::assertCount(5, $clusters);

        $total = array_sum(array_map('count', $clusters));
        self::assertSame(50, $total);
    }

    private function order(string $id, float $lat, float $lng, float $weight = 0.0): DeliveryOrder
    {
        return new DeliveryOrder($id, new Coordinate($lat, $lng), $weight);
    }
}
