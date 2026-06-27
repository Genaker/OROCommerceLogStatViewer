<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * The ranked result of a solve: the top-N routes, with the shortest flagged.
 */
final class RouteCollection
{
    /**
     * @param Route[] $routes
     */
    public function __construct(
        public readonly array $routes,
        public readonly int $shortestIndex,
        public readonly int $requestedCount,
    ) {
    }

    /**
     * @return array{routes: array<int, array<string, mixed>>, shortestIndex: int, requestedCount: int}
     */
    public function toArray(): array
    {
        return [
            'routes' => array_map(static fn (Route $route): array => $route->toArray(), $this->routes),
            'shortestIndex' => $this->shortestIndex,
            'requestedCount' => $this->requestedCount,
        ];
    }
}
