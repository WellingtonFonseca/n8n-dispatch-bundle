<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Service\Alert\FailingStepAlerts;
use MauticPlugin\N8nDispatchBundle\Service\ScheduledRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleans the step failure table and alerts about the steps that hold stuck contacts (Service/Alert/FailingStepAlerts.php).
 * Run by hand, or in the background by ScheduledJobsSubscriber with --run-id.
 */
#[AsCommand(name: self::NAME, description: 'Deletes the step failures that were resolved and alerts about the steps with stuck contacts.')]
class FailureSweepCommand extends Command
{
    public const NAME = 'n8ndispatch:failure:sweep';

    public function __construct(private FailingStepAlerts $alerts, private ScheduledRun $scheduledRun)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        ScheduledRun::addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->scheduledRun->run(ScheduledRun::id($input), function () use ($output): array {
            $result = $this->alerts->sweep(new \DateTimeImmutable());
            $line   = sprintf('cleaned %d, failing steps %d, stuck contacts %d', $result['cleaned'], $result['steps'], $result['contacts']);
            $output->writeln($line);

            return ['ok' => true, 'summary' => $line];
        });

        return Command::SUCCESS;
    }
}
