<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Contract\VRPSolverInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPSolution;
use Genaker\Bundle\ComiVoyager\Solver\VRPSolverRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;

class VRPSolverRegistryTest extends TestCase
{
    public function testGetByName(): void
    {
        $registry = $this->registry(['local', 'google'], 'local');

        self::assertSame('local', $registry->get('local')->getName());
        self::assertSame('google', $registry->get('google')->getName());
    }

    public function testGetDefaultFromConfig(): void
    {
        $registry = $this->registry(['local', 'google'], 'google');
        self::assertSame('google', $registry->get()->getName());
    }

    public function testGetDefaultFallsBackToLocal(): void
    {
        $registry = $this->registry(['local', 'google'], null);
        self::assertSame('local', $registry->get()->getName());
    }

    public function testGetUnknownThrows(): void
    {
        $registry = $this->registry(['local'], 'local');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown VRP solver "bogus"/');
        $registry->get('bogus');
    }

    public function testGetAvailableNames(): void
    {
        $registry = $this->registry(['local', 'google'], 'local');
        self::assertSame(['local', 'google'], $registry->getAvailableNames());
    }

    private function registry(array $names, ?string $defaultName): VRPSolverRegistry
    {
        $solvers = [];
        foreach ($names as $name) {
            $solver = $this->createMock(VRPSolverInterface::class);
            $solver->method('getName')->willReturn($name);
            $solver->method('solve')->willReturn(new VRPSolution());
            $solvers[] = $solver;
        }

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnMap([
            ['genaker_comi_voyager.vrp_solver', false, false, null, $defaultName],
        ]);

        return new VRPSolverRegistry($solvers, $config);
    }
}
