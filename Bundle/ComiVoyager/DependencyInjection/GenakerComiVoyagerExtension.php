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
    private const POSTGIS_DSN_DEFAULT = 'postgresql://comivoyager:comivoyager@comivoyager_postgis:5432/comivoyager';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);
        $container->prependExtensionConfig($this->getAlias(), SettingsBuilder::getSettings($config));

        // Provide a default for the PostGIS DSN env var, mirroring the
        // `parameters: env(X): <default>` pattern from config/config.yml,
        // so the bundle is self-contained and needs no app-level config.
        if (!$container->hasParameter('env(' . self::POSTGIS_DSN_ENV . ')')) {
            $container->setParameter('env(' . self::POSTGIS_DSN_ENV . ')', self::POSTGIS_DSN_DEFAULT);
        }

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__) . '/Resources/config'));
        $loader->load('services.yml');
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

        $container->prependExtensionConfig('doctrine', [
            'dbal' => [
                'connections' => [
                    'comivoyager_postgis' => [
                        'url' => '%env(' . self::POSTGIS_DSN_ENV . ')%',
                        'server_version' => '17',
                    ],
                ],
            ],
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
        ]);
    }

    public function getAlias(): string
    {
        return 'genaker_comi_voyager';
    }
}
