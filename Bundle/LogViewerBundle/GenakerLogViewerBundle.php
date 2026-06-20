<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle;

use Genaker\Bundle\LogViewerBundle\DependencyInjection\Compiler\RegisterDatabaseLogHandlerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class GenakerLogViewerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterDatabaseLogHandlerPass());
    }
}
