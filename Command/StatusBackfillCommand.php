<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Service\StatusBackfill;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the History of the dispatches made before it existed — see
 * Service/StatusBackfill.php: each dispatch of the campaign logs becomes the
 * first entry of its log's history and its ids start 'pending'; then n8n is
 * asked how they ended up (the same call as n8ndispatch:status:poll). Run once
 * after the plugin is updated; running it again is harmless, except with
 * --reset, which deletes the whole history first.
 */
#[AsCommand(name: 'n8ndispatch:status:backfill', description: 'Builds the History of the Email/SMS/HSM dispatches made before it existed, then asks n8n for their status.')]
class StatusBackfillCommand extends Command
{
    public function __construct(private StatusBackfill $backfill, private StatusPoller $poller, private StatusPollSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since-days', null, InputOption::VALUE_REQUIRED, 'How many days back to look.', '7')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'How many campaign logs to read at a time.', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be registered, write nothing.')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Delete the WHOLE history (every dispatch entry, tracking row and callback) before rebuilding it from the campaign logs.')
            ->addOption('no-poll', null, InputOption::VALUE_NONE, 'Do not ask n8n for the status of the ids afterwards.');
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
        $reset   = (bool) $input->getOption('reset');
        $summary = $this->backfill->run($sinceDays, $batch, $dryRun, new \DateTimeImmutable(), $reset);

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing was written.</comment>');
        } elseif ($reset) {
            $output->writeln('<comment>The history was deleted and rebuilt from the campaign logs.</comment>');
        }

        $output->writeln(sprintf(
            'scanned %d, dispatches %d, %s %d, already tracked %d, skipped %d',
            $summary['scanned'],
            $summary['eligible'],
            $dryRun ? 'would be registered' : 'registered',
            $summary['registered'],
            $summary['alreadyTracked'],
            $summary['skipped'],
        ));

        if ($dryRun || $input->getOption('no-poll')) {
            return Command::SUCCESS;
        }

        // Then ask n8n how the ids ended up, as far back as the backfill looked.
        $failed = false;

        foreach (DispatchTracking::CHANNELS as $channel) {
            $poll = $this->poller->poll($channel, $this->settings->current()['batch'], $sinceDays, DispatchTracking::OUTCOME_PENDING, null, new \DateTimeImmutable());

            if (null !== $poll['error']) {
                $failed = true;
                $output->writeln(sprintf('<error>%s: %s</error>', $channel, $poll['error']));

                continue;
            }

            $output->writeln(sprintf(
                '%s: asked %d, changed %d, unchanged %d, unknown %d, invalid %d, %d calls',
                $channel,
                $poll['requested'],
                $poll['changed'],
                $poll['unchanged'],
                $poll['unknown'],
                $poll['invalid'],
                $poll['calls'],
            ));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
