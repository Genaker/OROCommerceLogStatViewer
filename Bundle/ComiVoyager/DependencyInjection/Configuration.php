<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const string ROOT_NODE = 'genaker_comi_voyager';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();

        if (!$rootNode instanceof ArrayNodeDefinition) {
            // TreeBuilder defaults to the 'array' root type, so getRootNode() always
            // returns an ArrayNodeDefinition here; this guards SettingsBuilder::append().
            throw new \LogicException('Expected the config tree root node to be an ArrayNodeDefinition.');
        }

        SettingsBuilder::append(
            $rootNode,
            [
                'distance_provider' => ['type' => 'scalar', 'value' => 'haversine'],
                'geocoder' => ['type' => 'scalar', 'value' => 'nominatim'],
                'osrm_base_url' => ['type' => 'scalar', 'value' => 'https://router.project-osrm.org'],
                'google_api_key' => ['type' => 'scalar', 'value' => null],
                'default_route_count' => ['type' => 'scalar', 'value' => 3],
                'enable_geocode_cache' => ['type' => 'boolean', 'value' => true],
                'geocode_cache_ttl_days' => ['type' => 'scalar', 'value' => 30],
                'max_addresses' => ['type' => 'scalar', 'value' => 9],
            ]
        );

        return $treeBuilder;
    }
}
