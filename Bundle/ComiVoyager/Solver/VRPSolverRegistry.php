<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Solver;

use Genaker\Bundle\ComiVoyager\Core\Contract\VRPSolverInterface;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Resolves the configured (or per-request) VRP solver backend.
 *
 * Two backends are shipped:
 *  - "local"  — pure-PHP K-Means + TSP (free, offline, Haversine distances)
 *  - "google" — Google Cloud Fleet Routing (paid, real-road, traffic-aware)
 */
final class VRPSolverRegistry
{
    /** @var array<string, VRPSolverInterface> */
    private array $solvers = [];

    /**
     * @param iterable<VRPSolverInterface> $solvers
     */
    public function __construct(
        iterable $solvers,
        private readonly ConfigManager $configManager,
    ) {
        foreach ($solvers as $solver) {
            $this->solvers[$solver->getName()] = $solver;
        }
    }

    public function get(?string $name = null): VRPSolverInterface
    {
        $name ??= $this->getDefault();

        if (!isset($this->solvers[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown VRP solver "%s". Available: %s',
                $name,
                implode(', ', array_keys($this->solvers)),
            ));
        }

        return $this->solvers[$name];
    }

    /** @return string[] */
    public function getAvailableNames(): array
    {
        return array_keys($this->solvers);
    }

    private function getDefault(): string
    {
        return (string) ($this->configManager->get('genaker_comi_voyager.vrp_solver') ?? 'local');
    }
}
