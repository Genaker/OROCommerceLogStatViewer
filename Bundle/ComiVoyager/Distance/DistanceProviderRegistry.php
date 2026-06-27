<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Distance;

use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Exception\DistanceProviderUnavailableException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Resolves the configured (or explicitly requested) distance matrix provider.
 */
final class DistanceProviderRegistry
{
    /** @var array<string, DistanceMatrixProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<DistanceMatrixProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly ConfigManager $configManager,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function get(?string $name = null): DistanceMatrixProviderInterface
    {
        $name ??= (string) $this->configManager->get('genaker_comi_voyager.distance_provider') ?: 'haversine';

        $provider = $this->providers[$name] ?? null;
        if ($provider === null) {
            throw new DistanceProviderUnavailableException(sprintf('Unknown distance provider "%s".', $name));
        }

        return $provider;
    }
}
