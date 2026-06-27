<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Solver;

use Genaker\Bundle\ComiVoyager\Core\Contract\VRPSolverInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\RouteStop;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPRoute;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPSolution;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * VRP solver backed by Google Cloud Fleet Routing (Route Optimization API).
 *
 * Sends the full problem (depot, vehicles, shipments with capacity/time
 * constraints) to Google in one request and gets back optimized routes with
 * real-road distances, per-stop ETAs, and traffic-aware durations.
 *
 * API: POST https://routeoptimization.googleapis.com/v1/projects/{project}/optimizeTours
 *
 * @see https://cloud.google.com/optimization/docs/reference/rest/v1/projects/optimizeTours
 */
final class GoogleFleetRoutingSolver implements VRPSolverInterface
{
    private const API_URL = 'https://routeoptimization.googleapis.com/v1/projects/%s:optimizeTours';

    private const KM_TO_MILES = 0.621371;
    private const EARTH_RADIUS_MILES = 3958.8;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getName(): string
    {
        return 'google';
    }

    public function solve(
        array $orders,
        array $vehicles,
        Coordinate $depot,
        float $maxRadiusMiles = 100.0,
    ): VRPSolution {
        if (empty($orders) || empty($vehicles)) {
            return new VRPSolution();
        }

        $apiKey = $this->getApiKey();
        $projectId = $this->getProjectId();

        // Filter out-of-range orders before sending to Google
        $inRange = [];
        $outOfRange = [];
        if ($maxRadiusMiles > 0) {
            foreach ($orders as $order) {
                if ($this->haversineDistance($depot, $order->getCoordinate()) > $maxRadiusMiles) {
                    $outOfRange[] = $order;
                } else {
                    $inRange[] = $order;
                }
            }
        } else {
            $inRange = $orders;
        }

        if (empty($inRange)) {
            return new VRPSolution([], [], $outOfRange);
        }

        $requestBody = $this->buildRequest($inRange, $vehicles, $depot);

        try {
            $url = sprintf(self::API_URL, $projectId);
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Goog-Api-Key' => $apiKey,
                ],
                'json' => $requestBody,
                'timeout' => 30,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('GoogleFleetRoutingSolver request failed', ['error' => $e->getMessage()]);
            throw new DistanceProviderUnavailableException(
                'Google Route Optimization API request failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->parseResponse($data, $inRange, $vehicles, $depot, $outOfRange);
    }

    /**
     * Build the optimizeTours request body.
     *
     * @param DeliveryOrder[] $orders
     * @param Vehicle[] $vehicles
     */
    public function buildRequest(array $orders, array $vehicles, Coordinate $depot): array
    {
        $shipments = [];
        foreach ($orders as $i => $order) {
            $shipment = [
                'label' => $order->getId(),
                'deliveries' => [[
                    'arrivalWaypoint' => [
                        'location' => [
                            'latLng' => [
                                'latitude'  => $order->getCoordinate()->lat,
                                'longitude' => $order->getCoordinate()->lng,
                            ],
                        ],
                    ],
                    'duration' => sprintf('%ds', (int) ($vehicles[0]->getServiceTimeMinutes() * 60)),
                ]],
            ];

            if ($order->getWeightLbs() > 0) {
                $shipment['loadDemands'] = [
                    'weight_lbs' => ['amount' => (string) (int) $order->getWeightLbs()],
                ];
            }

            $shipments[] = $shipment;
        }

        $vehicleSpecs = [];
        foreach ($vehicles as $v) {
            $start = $v->resolveStart($depot);
            $end = $v->resolveEnd($depot);

            $spec = [
                'label' => 'Driver ' . $v->getId(),
                'startWaypoint' => [
                    'location' => [
                        'latLng' => ['latitude' => $start->lat, 'longitude' => $start->lng],
                    ],
                ],
            ];

            if ($end !== null) {
                $spec['endWaypoint'] = [
                    'location' => [
                        'latLng' => ['latitude' => $end->lat, 'longitude' => $end->lng],
                    ],
                ];
            }

            if ($v->hasCapacityLimit()) {
                $spec['loadLimits'] = [
                    'weight_lbs' => ['maxLoad' => (string) (int) $v->getCapacityLbs()],
                ];
            }

            if ($v->hasTimeLimit()) {
                $seconds = (int) ($v->getMaxWorkHours() * 3600);
                $spec['routeDurationLimit'] = ['maxDuration' => sprintf('%ds', $seconds)];
            }

            if ($v->hasDistanceLimit()) {
                $meters = (int) ($v->getMaxDistanceMiles() / self::KM_TO_MILES * 1000);
                $spec['routeDistanceLimit'] = ['maxMeters' => $meters];
            }

            if ($v->hasStopLimit()) {
                $spec['extraVisitDurationForVisitType'] = [];
                // Google doesn't have a direct max_stops; we enforce it in post-processing
            }

            $vehicleSpecs[] = $spec;
        }

        return [
            'model' => [
                'shipments' => $shipments,
                'vehicles'  => $vehicleSpecs,
            ],
            'searchMode' => 1, // RETURN_FAST
        ];
    }

    /**
     * Parse Google's optimizeTours response into a VRPSolution.
     *
     * @param DeliveryOrder[] $orders
     * @param Vehicle[] $vehicles
     * @param DeliveryOrder[] $outOfRange
     */
    public function parseResponse(
        array $data,
        array $orders,
        array $vehicles,
        Coordinate $depot,
        array $outOfRange = [],
    ): VRPSolution {
        $orderMap = [];
        foreach ($orders as $i => $o) {
            $orderMap[$i] = $o;
        }

        $routes = [];
        $assignedIndexes = [];
        $googleRoutes = $data['routes'] ?? [];

        foreach ($googleRoutes as $ri => $gRoute) {
            $vehicle = $vehicles[$ri] ?? $vehicles[0];
            $route = new VRPRoute($vehicle);
            $stops = [];
            $details = [];

            $visits = $gRoute['visits'] ?? [];
            $transitions = $gRoute['transitions'] ?? [];

            $cumulativeMiles = 0.0;
            $seq = 0;

            foreach ($visits as $vi => $visit) {
                $shipmentIndex = $visit['shipmentIndex'] ?? 0;
                $order = $orderMap[$shipmentIndex] ?? null;
                if ($order === null) {
                    continue;
                }

                $assignedIndexes[$shipmentIndex] = true;
                $stops[] = $order;
                $seq++;

                // Leg distance from transition (index = vi + 1 because transition[0] is depot→first)
                $transition = $transitions[$vi + 1] ?? $transitions[$vi] ?? null;
                $legMeters = 0;
                if ($transition !== null) {
                    $legMeters = $transition['travelDistanceMeters'] ?? 0;
                }
                $legMiles = $legMeters / 1000.0 * self::KM_TO_MILES;
                $cumulativeMiles += $legMiles;

                // ETA from visit start time
                $arrivalHours = $this->parseSecondsFromDuration($visit['startTime'] ?? '0s') / 3600.0;
                $serviceHours = $vehicle->getServiceTimeMinutes() / 60.0;

                $details[] = new RouteStop(
                    $seq,
                    $order,
                    $legMiles,
                    $cumulativeMiles,
                    $arrivalHours,
                    $arrivalHours + $serviceHours,
                );
            }

            $route->setStops($stops);
            $route->setStopDetails($details);

            // Total distance from Google's metrics
            $totalMeters = (int) ($gRoute['metrics']['travelDistanceMeters'] ?? 0);
            $route->setTotalDistanceMiles($totalMeters / 1000.0 * self::KM_TO_MILES);

            // Final return leg
            $lastTransition = end($transitions);
            $finalLegMeters = $lastTransition ? ($lastTransition['travelDistanceMeters'] ?? 0) : 0;
            $route->setFinalLegMiles($finalLegMeters / 1000.0 * self::KM_TO_MILES);

            $routes[] = $route;
        }

        // Unassigned: orders not in any route
        $unassigned = [];
        foreach ($orders as $i => $order) {
            if (!isset($assignedIndexes[$i])) {
                $unassigned[] = $order;
            }
        }

        // Also check Google's skippedShipments
        foreach ($data['skippedShipments'] ?? [] as $skipped) {
            $idx = $skipped['index'] ?? null;
            if ($idx !== null && isset($orderMap[$idx]) && !isset($assignedIndexes[$idx])) {
                // Already in $unassigned from the loop above
            }
        }

        return new VRPSolution($routes, $unassigned, $outOfRange);
    }

    private function parseSecondsFromDuration(string $duration): float
    {
        // Google returns durations as "123.456s" or ISO timestamps
        if (preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $m)) {
            return (float) $m[1];
        }
        // Try ISO 8601 timestamp relative to epoch
        if (str_contains($duration, 'T')) {
            try {
                $dt = new \DateTimeImmutable($duration);
                $epoch = new \DateTimeImmutable('1970-01-01T00:00:00Z');
                return (float) ($dt->getTimestamp() - $epoch->getTimestamp());
            } catch (\Exception) {
                return 0.0;
            }
        }
        return 0.0;
    }

    private function getApiKey(): string
    {
        $key = (string) $this->configManager->get('genaker_comi_voyager.google_api_key');
        if ($key === '') {
            throw new DistanceProviderUnavailableException(
                'Google Route Optimization API requires an API key. Set it in System Configuration → ComiVoyager.'
            );
        }
        return $key;
    }

    private function getProjectId(): string
    {
        $id = (string) $this->configManager->get('genaker_comi_voyager.google_project_id');
        if ($id === '') {
            throw new DistanceProviderUnavailableException(
                'Google Route Optimization API requires a Google Cloud project ID. Set it in System Configuration → ComiVoyager.'
            );
        }
        return $id;
    }

    private function haversineDistance(Coordinate $a, Coordinate $b): float
    {
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $sinLat = sin($dLat / 2);
        $sinLng = sin($dLng / 2);
        $h = $sinLat * $sinLat + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * $sinLng * $sinLng;
        return 2 * self::EARTH_RADIUS_MILES * asin(sqrt($h));
    }
}
