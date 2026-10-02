<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Service\StatusBackfill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Starts tracking the dispatches made before the status poll existed — see
 * Service/StatusBackfill.php. Run once after the plugin is updated; running it
 * again is harmless.
 */
#[AsCommand(name: 'n8ndispatch:status:backfill', description: 'Starts tracking the Email/SMS/HSM dispatches made before the status poll existed.')]
class StatusBackfillCommand extends Command
{
    public function __construct(private StatusBackfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since-days', null, InputOption::VALUE_REQUIRED, 'How many days back to look.', '7')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'How many campaign logs to read at a time.', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be registered, write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sinceDays = filter_var($input->getOption('since-days'), FILTER_VALIDATE_INT);
        $batch     = filter_var($input->getOption('batch'), FILTER_VALIDATE_INT);

        if (false === $sinceDays || $sinceDays < 1 || false === $batch || $batch < 1) {
            $output->writeln('<error>--since-days and --batch must be whole numbers of at least 1.</error>');

            return Command::INVALID;
        }

        $dryRun  = (bool) $input->getOption('dry-run');
        $summary = $this->backfill->run($sinceDays, $batch, $dryRun, new \DateTimeImmutable());

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing was written.</comment>');
        }

        $output->writeln(sprintf(
            'scanned %d, with ids %d, %s %d, already tracked %d, skipped %d',
            $summary['scanned'],
            $summary['eligible'],
            $dryRun ? 'would be registered' : 'registered',
            $summary['registered'],
            $summary['alreadyTracked'],
            $summary['skipped'],
        ));

        return Command::SUCCESS;
    }
}
