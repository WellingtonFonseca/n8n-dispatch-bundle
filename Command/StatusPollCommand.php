<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\ScheduledRun;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Asks n8n how the dispatches that are still pending ended up — see
 * Service/StatusPoller.php. Run by hand, by a real cron, or — what the
 * plugin does by itself when its status poll is switched on — in the
 * background by EventListener/ScheduledJobsSubscriber, which passes --run-id so
 * the run is recorded for the plugin's settings screen.
 */
#[AsCommand(name: self::NAME, description: 'Asks n8n for the outcome of the Email/SMS/HSM dispatches still pending.')]
class StatusPollCommand extends Command
{
    public const NAME = 'n8ndispatch:status:poll';

    private const ALERT_COOLDOWN_SECONDS = 21600;

    public function __construct(private StatusPoller $poller, private ScheduledRun $scheduledRun, private StatusPollSettings $settings, private ?AlertSender $alerts = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'email, sms or hsm. Default: all three.')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, "Ids asked about per call to n8n. Default: the one on the plugin's settings screen (100 unless changed).")
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Most calls per channel in one run (a safety net: the run goes on until the queue is empty).', (string) StatusPoller::MAX_CALLS)
            ->addOption('max-age-days', null, InputOption::VALUE_REQUIRED, 'Ids dispatched longer ago than this stop being asked about.', '7')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED, 'Which outcome to ask about: pending (default), or error to re-check failures.', DispatchTracking::OUTCOME_PENDING)
            ->addOption('webhook-url', null, InputOption::VALUE_REQUIRED, 'Call this URL instead of the configured webhook (for tests).');
        ScheduledRun::addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');
        $outcome = (string) $input->getOption('outcome');
        $batch   = null === $input->getOption('batch') ? $this->settings->current()['batch'] : filter_var($input->getOption('batch'), FILTER_VALIDATE_INT);
        $maxCalls = filter_var($input->getOption('max-calls'), FILTER_VALIDATE_INT);
        $maxAge  = (int) $input->getOption('max-age-days');

        if (null !== $channel && !in_array($channel, DispatchTracking::CHANNELS, true)) {
            $output->writeln('<error>--channel must be email, sms or hsm.</error>');

            return Command::INVALID;
        }

        if (!in_array($outcome, [DispatchTracking::OUTCOME_PENDING, DispatchTracking::OUTCOME_ERROR], true) || false === $batch || $batch < 1 || false === $maxCalls || $maxCalls < 1 || $maxAge < 1) {
            $output->writeln('<error>--outcome must be pending or error; --batch, --max-calls and --max-age-days must be whole numbers of at least 1.</error>');

            return Command::INVALID;
        }

        $override = $input->getOption('webhook-url');
        $override = is_string($override) ? $override : null;

        $result = $this->scheduledRun->run(
            ScheduledRun::id($input),
            fn (): array => $this->pollChannels($channel, $batch, $maxAge, $outcome, $override, $maxCalls, $output),
            // one alert per cooldown: a poll that keeps failing runs every interval
            fn (int $runId, string $summary) => $this->alerts?->send('status_poll.failed', ['run_id' => $runId, 'summary' => $summary], 'status_poll', self::ALERT_COOLDOWN_SECONDS),
        );

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Polls one channel or all three. A channel that answers with an error does not stop the others: the run ends in
     * error at the end, with one line per channel.
     *
     * @return array{ok: bool, summary: string}
     */
    private function pollChannels(?string $channel, int $batch, int $maxAge, string $outcome, ?string $override, int $maxCalls, OutputInterface $output): array
    {
        $failed = false;
        $lines  = [];

        foreach (null === $channel ? DispatchTracking::CHANNELS : [$channel] as $name) {
            $summary = $this->poller->poll($name, $batch, $maxAge, $outcome, $override, new \DateTimeImmutable(), $maxCalls);

            if (null !== $summary['error']) {
                $failed  = true;
                $lines[] = StatusPollSummary::errorLine($name, $summary['error']);
                $output->writeln('<error>'.$lines[array_key_last($lines)].'</error>');

                continue;
            }

            $lines[] = StatusPollSummary::line($name, $summary);
            $output->writeln($lines[array_key_last($lines)]);
        }

        return ['ok' => !$failed, 'summary' => implode("\n", $lines)];
    }
}
