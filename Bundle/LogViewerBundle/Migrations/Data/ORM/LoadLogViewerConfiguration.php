<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Data\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets default configuration values for Log Viewer bundle.
 */
class LoadLogViewerConfiguration extends AbstractFixture implements OrderedFixtureInterface, ContainerAwareInterface
{
    private ContainerInterface $container;

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $configManager = $this->container->get('oro_config.manager');

        $configManager->set('genaker_log_viewer.enabled', true);
        $configManager->flush();
    }

    #[\Override]
    public function getOrder(): int
    {
        return 2000;
    }
}
