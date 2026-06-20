<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Geocoder;

use Doctrine\ORM\EntityManagerInterface;
use Genaker\Bundle\ComiVoyager\Core\Contract\GeocoderInterface;
use Genaker\Bundle\ComiVoyager\Exception\GeocodingFailedException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Resolves the configured (or explicitly requested) geocoder, optionally
 * wrapping it in a database-backed cache.
 */
final class GeocoderRegistry
{
    /** @var array<string, GeocoderInterface> */
    private array $geocoders = [];

    /**
     * @param iterable<GeocoderInterface> $geocoders
     */
    public function __construct(
        iterable $geocoders,
        private readonly ConfigManager $configManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
        foreach ($geocoders as $geocoder) {
            $this->geocoders[$geocoder->getName()] = $geocoder;
        }
    }

    public function get(?string $name = null): GeocoderInterface
    {
        $name ??= (string) $this->configManager->get('genaker_comi_voyager.geocoder') ?: 'nominatim';

        $geocoder = $this->geocoders[$name] ?? null;
        if ($geocoder === null) {
            throw new GeocodingFailedException(sprintf('Unknown geocoder "%s".', $name));
        }

        if ((bool) $this->configManager->get('genaker_comi_voyager.enable_geocode_cache')) {
            $ttlDays = (int) $this->configManager->get('genaker_comi_voyager.geocode_cache_ttl_days') ?: 30;

            return new CachingGeocoder($geocoder, $this->entityManager, $ttlDays);
        }

        return $geocoder;
    }
}
