<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class GenakerComiVoyagerExtension extends Extension implements PrependExtensionInterface
{
    private const POSTGIS_DSN_ENV = 'ORO_COMIVOYAGER_POSTGIS_DSN';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);
        $container->prependExtensionConfig($this->getAlias(), SettingsBuilder::getSettings($config));

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__) . '/Resources/config'));
        $loader->load('services.yml');

        // Remove the PostGIS distance provider if no DSN is configured.
        // This prevents Doctrine from trying to connect to a non-existent
        // PostGIS server during schema checks / requirements validation.
        if (!$this->isPostgisConfigured()) {
            $container->removeDefinition('Genaker\Bundle\ComiVoyager\Distance\PostgisDistanceMatrixProvider');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('monolog', [
            'channels' => ['comivoyager'],
            'handlers' => [
                'comivoyager_file' => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/comivoyager.log',
                    'level' => 'debug',
                    'channels' => ['comivoyager'],
                ],
            ],
        ]);

        $doctrineConfig = [
            'orm' => [
                'mappings' => [
                    'GenakerComiVoyagerBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__) . '/Entity',
                        'prefix' => 'Genaker\Bundle\ComiVoyager\Entity',
                        'alias' => 'GenakerComiVoyagerBundle',
                    ],
                ],
            ],
        ];

        // Only register the PostGIS DBAL connection when the DSN env var is
        // explicitly set. This avoids Doctrine trying to connect to a
        // non-existent server during cache warmup, schema checks, or
        // requirements validation.
        if ($this->isPostgisConfigured()) {
            $doctrineConfig['dbal'] = [
                'connections' => [
                    'comivoyager_postgis' => [
                        'url' => '%env(' . self::POSTGIS_DSN_ENV . ')%',
                        'server_version' => '17',
                    ],
                ],
            ];
        }

        $container->prependExtensionConfig('doctrine', $doctrineConfig);
    }

    public function getAlias(): string
    {
        return 'genaker_comi_voyager';
    }

    private function isPostgisConfigured(): bool
    {
        $dsn = getenv(self::POSTGIS_DSN_ENV);

        return $dsn !== false && $dsn !== '';
    }
}
