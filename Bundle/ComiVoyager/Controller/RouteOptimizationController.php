<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Controller;

use Genaker\Bundle\ComiVoyager\Core\Exception\InsufficientAddressesException;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Genaker\Bundle\ComiVoyager\Exception\GeocodingFailedException;
use Genaker\Bundle\ComiVoyager\Service\RouteOptimizationService;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /comivoyager/optimize
 *
 * Body:
 *   {
 *     "addresses": [{"label": "A", "lat": 1.0, "lng": 2.0}, {"label": "B", "address": "..."}, ...],
 *     "method": "haversine|vincenty|osrm|google|postgis",
 *     "geocoder": "nominatim|google",
 *     "routes": 3,
 *     "returnToStart": false,
 *     "depotIndex": null
 *   }
 */
class RouteOptimizationController extends AbstractController
{
    public function __construct(
        private readonly RouteOptimizationService $routeOptimizationService,
    ) {
    }

    #[AclAncestor('genaker_comivoyager_optimize')]
    public function optimizeAction(Request $request): JsonResponse
    {
        try {
            $payload = json_decode((string) $request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return $this->json(['error' => 'Invalid JSON: ' . $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload) || !isset($payload['addresses']) || !is_array($payload['addresses'])) {
            return $this->json(['error' => 'Field "addresses" is required and must be an array.'], Response::HTTP_BAD_REQUEST);
        }

        $options = new SolveOptions(
            returnToStart: (bool) ($payload['returnToStart'] ?? false),
            depotIndex: isset($payload['depotIndex']) ? (int) $payload['depotIndex'] : null,
        );

        try {
            $result = $this->routeOptimizationService->optimize(
                $payload['addresses'],
                isset($payload['method']) ? (string) $payload['method'] : null,
                isset($payload['geocoder']) ? (string) $payload['geocoder'] : null,
                isset($payload['routes']) ? (int) $payload['routes'] : null,
                $options,
            );
        } catch (InsufficientAddressesException|\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (GeocodingFailedException|DistanceProviderUnavailableException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result->toArray());
    }
}
