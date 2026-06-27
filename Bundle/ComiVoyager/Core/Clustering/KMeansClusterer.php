<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Clustering;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;

/**
 * K-Means clustering on lat/lng coordinates.
 *
 * Splits delivery orders into K geographic groups so that each group
 * contains nearby addresses — one group per vehicle.
 *
 * Uses Haversine distance for centroid assignment (not Euclidean on raw
 * lat/lng, which distorts at high latitudes).
 */
final class KMeansClusterer
{
    private const MAX_ITERATIONS = 100;
    private const CONVERGENCE_THRESHOLD = 0.001; // miles

    /**
     * @param DeliveryOrder[] $orders
     * @param int $k number of clusters
     * @param Coordinate|null $depot if set, seeds first centroid at depot
     * @return array<int, DeliveryOrder[]> cluster index => orders
     */
    public function cluster(array $orders, int $k, ?Coordinate $depot = null): array
    {
        if ($k <= 0 || count($orders) === 0) {
            return [];
        }

        if ($k >= count($orders)) {
            $result = [];
            foreach (array_values($orders) as $i => $order) {
                $result[$i] = [$order];
            }
            return $result;
        }

        $centroids = $this->initCentroids($orders, $k, $depot);
        $assignments = [];

        for ($iter = 0; $iter < self::MAX_ITERATIONS; $iter++) {
            $newAssignments = $this->assign($orders, $centroids);

            if ($newAssignments === $assignments) {
                break;
            }

            $assignments = $newAssignments;
            $newCentroids = $this->recomputeCentroids($orders, $assignments, $k);

            $maxShift = $this->maxCentroidShift($centroids, $newCentroids);
            $centroids = $newCentroids;

            if ($maxShift < self::CONVERGENCE_THRESHOLD) {
                break;
            }
        }

        return $this->buildClusters($orders, $assignments, $k);
    }

    /**
     * @param DeliveryOrder[] $orders
     * @return Coordinate[] initial centroids
     */
    private function initCentroids(array $orders, int $k, ?Coordinate $depot): array
    {
        // K-Means++ initialization for better starting points
        $orders = array_values($orders);
        $centroids = [];

        if ($depot !== null) {
            $centroids[] = $depot;
        } else {
            $centroids[] = $orders[array_rand($orders)]->getCoordinate();
        }

        while (count($centroids) < $k) {
            $distances = [];
            $totalDist = 0.0;

            foreach ($orders as $i => $order) {
                $minDist = PHP_FLOAT_MAX;
                foreach ($centroids as $c) {
                    $d = $this->haversineDistance($order->getCoordinate(), $c);
                    $minDist = min($minDist, $d);
                }
                $distances[$i] = $minDist * $minDist; // squared for weighting
                $totalDist += $distances[$i];
            }

            if ($totalDist <= 0) {
                $centroids[] = $orders[array_rand($orders)]->getCoordinate();
                continue;
            }

            // Weighted random selection
            $threshold = mt_rand() / mt_getrandmax() * $totalDist;
            $cumulative = 0.0;
            foreach ($distances as $i => $d) {
                $cumulative += $d;
                if ($cumulative >= $threshold) {
                    $centroids[] = $orders[$i]->getCoordinate();
                    break;
                }
            }
        }

        return $centroids;
    }

    /**
     * @param DeliveryOrder[] $orders
     * @param Coordinate[] $centroids
     * @return int[] order index => cluster index
     */
    private function assign(array $orders, array $centroids): array
    {
        $assignments = [];
        foreach ($orders as $i => $order) {
            $minDist = PHP_FLOAT_MAX;
            $bestCluster = 0;
            foreach ($centroids as $c => $centroid) {
                $d = $this->haversineDistance($order->getCoordinate(), $centroid);
                if ($d < $minDist) {
                    $minDist = $d;
                    $bestCluster = $c;
                }
            }
            $assignments[$i] = $bestCluster;
        }
        return $assignments;
    }

    /**
     * @param DeliveryOrder[] $orders
     * @param int[] $assignments
     * @return Coordinate[]
     */
    private function recomputeCentroids(array $orders, array $assignments, int $k): array
    {
        $sums = array_fill(0, $k, ['lat' => 0.0, 'lng' => 0.0, 'count' => 0]);

        foreach ($assignments as $i => $cluster) {
            $coord = $orders[$i]->getCoordinate();
            $sums[$cluster]['lat'] += $coord->lat;
            $sums[$cluster]['lng'] += $coord->lng;
            $sums[$cluster]['count']++;
        }

        $centroids = [];
        foreach ($sums as $s) {
            if ($s['count'] > 0) {
                $centroids[] = new Coordinate($s['lat'] / $s['count'], $s['lng'] / $s['count']);
            } else {
                // Empty cluster — pick a random order
                $randomOrder = $orders[array_rand($orders)];
                $centroids[] = $randomOrder->getCoordinate();
            }
        }

        return $centroids;
    }

    /**
     * @param Coordinate[] $old
     * @param Coordinate[] $new
     */
    private function maxCentroidShift(array $old, array $new): float
    {
        $maxShift = 0.0;
        foreach ($old as $i => $oldC) {
            if (isset($new[$i])) {
                $shift = $this->haversineDistance($oldC, $new[$i]);
                $maxShift = max($maxShift, $shift);
            }
        }
        return $maxShift;
    }

    /**
     * @param DeliveryOrder[] $orders
     * @param int[] $assignments
     * @return array<int, DeliveryOrder[]>
     */
    private function buildClusters(array $orders, array $assignments, int $k): array
    {
        $clusters = array_fill(0, $k, []);
        foreach ($assignments as $i => $cluster) {
            $clusters[$cluster][] = $orders[$i];
        }
        return $clusters;
    }

    private function haversineDistance(Coordinate $a, Coordinate $b): float
    {
        $earthRadiusMiles = 3958.8;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $sinLat = sin($dLat / 2);
        $sinLng = sin($dLng / 2);
        $h = $sinLat * $sinLat + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * $sinLng * $sinLng;
        return 2 * $earthRadiusMiles * asin(sqrt($h));
    }
}
