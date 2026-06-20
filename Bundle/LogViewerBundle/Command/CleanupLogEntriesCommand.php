<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'genaker:log-entry:cleanup',
    description: 'Delete old log entries from the database',
)]
class CleanupLogEntriesCommand extends Command
{
    private const int DEFAULT_RETENTION_HOURS = 24;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'hours',
            null,
            InputOption::VALUE_REQUIRED,
            'Delete entries older than this many hours',
            (string) self::DEFAULT_RETENTION_HOURS
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = max(1, (int) $input->getOption('hours'));

        $cutoff = new \DateTime("-{$hours} hours");
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');

        try {
            $qb = $this->connection->createQueryBuilder();
            $qb->delete('genaker_log_entry')
                ->where('created_at < :cutoff')
                ->setParameter('cutoff', $cutoffStr);

            $deleted = $qb->execute();
        } catch (\Throwable $e) {
            $output->writeln('<error>Cleanup failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Deleted %d log entries older than %d hours (before %s).</info>',
            $deleted,
            $hours,
            $cutoffStr
        ));

        return Command::SUCCESS;
    }
}
