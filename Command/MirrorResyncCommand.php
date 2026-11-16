<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Service\MirrorResync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSummary;
use MauticPlugin\N8nDispatchBundle\Service\ScheduledRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends to Mirror again the Emails that are out of sync (Service/MirrorResync.php). Run by hand, or in the background
 * by ScheduledJobsSubscriber with --run-id, so the run shows on the plugin's settings screen.
 */
#[AsCommand(name: self::NAME, description: 'Sends the Emails that are out of sync with Mirror again (email.save), one at a time.')]
class MirrorResyncCommand extends Command
{
    public const NAME = 'n8ndispatch:mirror:resync';

    public function __construct(private MirrorResync $resync, private ScheduledRun $scheduledRun, private MirrorResyncSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, "Most Emails sent in this run. Default: the plugin's settings screen (100 unless changed).")
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, "Milliseconds between two calls to n8n. Default: the plugin's settings screen (2000 unless changed).")
            ->addOption('email-id', null, InputOption::VALUE_REQUIRED, 'Send only this Email, whatever the state of its last sync.');
        ScheduledRun::addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settings->current();
        $batch    = $this->wholeNumber($input->getOption('batch'), $settings['batch'], 1);
        $delay    = $this->wholeNumber($input->getOption('delay'), $settings['delay'], 0);
        $emailId  = $this->wholeNumber($input->getOption('email-id'), 0, 1);

        if (null === $batch || null === $delay || null === $emailId) {
            $output->writeln('<error>--batch and --email-id must be whole numbers of at least 1; --delay of at least 0.</error>');

            return Command::INVALID;
        }

        $result = $this->scheduledRun->run(ScheduledRun::id($input), function () use ($emailId, $batch, $delay, $settings, $output): array {
            $summary = $emailId > 0
                ? $this->resync->runOne($emailId)
                : $this->resync->run($batch, $delay, $settings['refreshHours'], new \DateTimeImmutable());
            $line = MirrorResyncSummary::line($summary);
            $output->writeln(null === $summary['error'] ? $line : '<error>'.$line.'</error>');

            return ['ok' => null === $summary['error'], 'summary' => $line];
        });

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** The option as a whole number of at least $min, $default when it is not given, null when it is invalid. */
    private function wholeNumber(mixed $value, int $default, int $min): ?int
    {
        if (null === $value) {
            return $default;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return false === $number || $number < $min ? null : $number;
    }
}
