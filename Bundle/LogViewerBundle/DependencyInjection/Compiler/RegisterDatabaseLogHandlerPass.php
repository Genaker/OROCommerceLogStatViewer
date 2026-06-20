<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\DependencyInjection\Compiler;

use Genaker\Bundle\LogViewerBundle\Handler\DatabaseLogHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pushes DatabaseLogHandler onto Monolog's main logger at container compile time.
 *
 * MonologBundle only auto-registers handlers defined in monolog: YAML config,
 * so service-tagged handlers need a compiler pass to join the handler chain.
 */
class RegisterDatabaseLogHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('monolog.logger') || !$container->has(DatabaseLogHandler::class)) {
            return;
        }

        $loggerDef = $container->findDefinition('monolog.logger');
        $loggerDef->addMethodCall('pushHandler', [new Reference(DatabaseLogHandler::class)]);
    }
}
