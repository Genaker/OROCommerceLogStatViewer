<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Command;

use Genaker\Bundle\ComiVoyager\Core\ComiVoyager;
use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Distance\VincentyDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Exception\InsufficientAddressesException;
use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads a JSON array of addresses (each with a label and lat/lng) and prints
 * the top-N most efficient visiting orders as JSON.
 *
 * Input format:
 *   [{"label": "Customer A", "lat": 39.78, "lng": -89.65}, ...]
 */
#[AsCommand(
    name: 'comivoyager:optimize',
    description: 'Compute the top-N shortest delivery routes for a set of addresses.',
)]
final class OptimizeRouteCommand extends Command
{
    /**
     * @param iterable<DistanceMatrixProviderInterface> $distanceProviders
     */
    public function __construct(
        private readonly iterable $distanceProviders = [
            new HaversineDistanceMatrixProvider(),
            new VincentyDistanceMatrixProvider(),
        ],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'input',
                InputArgument::REQUIRED,
                'Path to a JSON file with addresses, or "-" to read from stdin'
            )
            ->addOption(
                'method',
                'm',
                InputOption::VALUE_REQUIRED,
                'Distance calculation method (haversine|vincenty)',
                'haversine'
            )
            ->addOption(
                'routes',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of top routes to return',
                '3'
            )
            ->addOption(
                'return-to-start',
                null,
                InputOption::VALUE_NONE,
                'Treat the route as a closed loop that returns to its starting stop'
            )
            ->addOption(
                'depot',
                null,
                InputOption::VALUE_REQUIRED,
                'Index (0-based) of the address that must be the route start'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $json = $this->readInput((string) $input->getArgument('input'));

        if ($json === null) {
            $io->error(sprintf('Could not read input "%s".', $input->getArgument('input')));

            return Command::FAILURE;
        }

        try {
            $addresses = $this->parseAddresses($json);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            $io->error('Invalid input: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $provider = $this->resolveProvider((string) $input->getOption('method'));

        if ($provider === null) {
            $io->error(sprintf('Unknown method "%s".', $input->getOption('method')));

            return Command::FAILURE;
        }

        $depotOption = $input->getOption('depot');
        $options = new SolveOptions(
            returnToStart: (bool) $input->getOption('return-to-start'),
            depotIndex: $depotOption !== null ? (int) $depotOption : null,
        );

        $engine = new ComiVoyager($provider);

        try {
            $result = $engine->optimize($addresses, (int) $input->getOption('routes'), $options);
        } catch (InsufficientAddressesException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $output->writeln((string) json_encode(
            $result->toArray(),
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR
        ));

        return Command::SUCCESS;
    }

    private function readInput(string $path): ?string
    {
        if ($path === '-') {
            $contents = stream_get_contents(\STDIN);

            return $contents === false ? null : $contents;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @return Address[]
     */
    private function parseAddresses(string $json): array
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Expected a JSON array of addresses.');
        }

        $addresses = [];

        foreach ($data as $position => $entry) {
            if (!is_array($entry) || !isset($entry['lat'], $entry['lng'])) {
                throw new \InvalidArgumentException(sprintf('Address at position %d must have "lat" and "lng".', $position));
            }

            $label = isset($entry['label']) ? (string) $entry['label'] : sprintf('Address %d', $position + 1);
            $addresses[] = new Address($label, new Coordinate((float) $entry['lat'], (float) $entry['lng']));
        }

        return $addresses;
    }

    private function resolveProvider(string $method): ?DistanceMatrixProviderInterface
    {
        foreach ($this->distanceProviders as $provider) {
            if ($provider->getName() === $method) {
                return $provider;
            }
        }

        return null;
    }
}
