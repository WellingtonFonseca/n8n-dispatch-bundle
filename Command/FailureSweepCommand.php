<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\Alert\FailingStepAlerts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleans the step failure table and alerts about the steps that hold stuck contacts (Service/Alert/FailingStepAlerts.php).
 * Run by hand, or in the background by ScheduledJobsSubscriber with --run-id.
 */
#[AsCommand(name: self::NAME, description: 'Deletes the step failures that were resolved and alerts about the steps with stuck contacts.')]
class FailureSweepCommand extends Command
{
    public const NAME = 'n8ndispatch:failure:sweep';

    public function __construct(private FailingStepAlerts $alerts, private PollRunRepository $runs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Set by the plugin when it starts a scheduled run: the run to close when this one ends.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runOption = $input->getOption('run-id');
        $runId     = null !== $runOption ? (int) $runOption : null;

        try {
            $result = $this->alerts->sweep(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // a scheduled run must always end up closed, or it would block the next sweep for two hours
            $this->runs->close($runId, PollRun::STATUS_ERROR, $e->getMessage(), new \DateTimeImmutable());

            throw $e;
        }

        $line = sprintf('cleaned %d, failing steps %d, stuck contacts %d', $result['cleaned'], $result['steps'], $result['contacts']);
        $output->writeln($line);
        $this->runs->close($runId, PollRun::STATUS_OK, $line, new \DateTimeImmutable());

        return Command::SUCCESS;
    }
}
