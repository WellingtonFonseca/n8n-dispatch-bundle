<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Asks n8n how the dispatches that are still pending ended up — see
 * Service/StatusPoller.php. Run by hand, by a real cron, or — what the
 * plugin does by itself when its status poll is switched on — in the
 * background by EventListener/StatusPollSubscriber, which passes --run-id so
 * the run is recorded for the plugin's settings screen.
 */
#[AsCommand(name: 'n8ndispatch:status:poll', description: 'Asks n8n for the outcome of the Email/SMS/HSM dispatches still pending.')]
class StatusPollCommand extends Command
{
    public function __construct(private StatusPoller $poller, private PollRunRepository $runs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'email, sms or hsm. Default: all three.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most ids asked about per channel in one run.', '200')
            ->addOption('max-age-days', null, InputOption::VALUE_REQUIRED, 'Ids dispatched longer ago than this stop being asked about.', '7')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED, 'Which outcome to ask about: pending (default), or error to re-check failures.', DispatchTracking::OUTCOME_PENDING)
            ->addOption('webhook-url', null, InputOption::VALUE_REQUIRED, 'Call this URL instead of the configured webhook (for tests).')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Set by the plugin when it starts a scheduled run: the run to close when this one ends.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');
        $outcome = (string) $input->getOption('outcome');
        $limit   = (int) $input->getOption('limit');
        $maxAge  = (int) $input->getOption('max-age-days');

        if (null !== $channel && !in_array($channel, DispatchTracking::CHANNELS, true)) {
            $output->writeln('<error>--channel must be email, sms or hsm.</error>');

            return Command::INVALID;
        }

        if (!in_array($outcome, [DispatchTracking::OUTCOME_PENDING, DispatchTracking::OUTCOME_ERROR], true) || $limit < 1 || $maxAge < 1) {
            $output->writeln('<error>--outcome must be pending or error; --limit and --max-age-days must be at least 1.</error>');

            return Command::INVALID;
        }

        $override = $input->getOption('webhook-url');
        $runId    = $input->getOption('run-id');
        $run      = null !== $runId ? $this->runs->find((int) $runId) : null;
        $failed   = false;
        $lines    = [];

        try {
            foreach (null === $channel ? DispatchTracking::CHANNELS : [$channel] as $name) {
                $summary = $this->poller->poll($name, $limit, $maxAge, $outcome, is_string($override) ? $override : null, new \DateTimeImmutable());

                if (null !== $summary['error']) {
                    $failed  = true;
                    $lines[] = sprintf('%s: %s', $name, $summary['error']);
                    $output->writeln(sprintf('<error>%s: %s</error>', $name, $summary['error']));

                    continue;
                }

                $lines[] = sprintf(
                    '%s: asked %d, changed %d, unchanged %d, unknown %d, invalid %d',
                    $name,
                    $summary['requested'],
                    $summary['changed'],
                    $summary['unchanged'],
                    $summary['unknown'],
                    $summary['invalid'],
                );
                $output->writeln($lines[array_key_last($lines)]);
            }
        } catch (\Throwable $e) {
            // A scheduled run must always end up closed, or the settings
            // screen would show it running forever.
            $this->closeRun($run, PollRun::STATUS_ERROR, $e->getMessage());

            throw $e;
        }

        $this->closeRun($run, $failed ? PollRun::STATUS_ERROR : PollRun::STATUS_OK, implode("\n", $lines));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function closeRun(?PollRun $run, string $status, string $summary): void
    {
        if (null === $run) {
            return;
        }

        $run->finish($status, $summary, new \DateTimeImmutable());
        $this->runs->saveEntity($run);
    }
}
