<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends to Mirror again the Emails that are out of sync (Service/MirrorResync.php). Run by hand, or in the background
 * by MirrorResyncSubscriber with --run-id, so the run shows on the plugin's settings screen.
 */
#[AsCommand(name: self::NAME, description: 'Sends the Emails that are out of sync with Mirror again (email.save), one at a time.')]
class MirrorResyncCommand extends Command
{
    public const NAME = 'n8ndispatch:mirror:resync';

    public function __construct(private MirrorResync $resync, private PollRunRepository $runs, private MirrorResyncSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, "Most Emails sent in this run. Default: the plugin's settings screen (100 unless changed).")
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, "Milliseconds between two calls to n8n. Default: the plugin's settings screen (2000 unless changed).")
            ->addOption('email-id', null, InputOption::VALUE_REQUIRED, 'Send only this Email, whatever the state of its last sync.')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Set by the plugin when it starts a scheduled run: the run to close when this one ends.');
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

        $runId = $input->getOption('run-id');
        $run   = null !== $runId ? $this->runs->find((int) $runId) : null;

        try {
            $summary = $emailId > 0
                ? $this->resync->runOne($emailId)
                : $this->resync->run($batch, $delay, $settings['refreshHours'], new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // A scheduled run must always end up closed, or the settings screen would show it running forever.
            $this->closeRun($run, PollRun::STATUS_ERROR, $e->getMessage());

            throw $e;
        }

        $line = self::summaryLine($summary);
        $output->writeln(null === $summary['error'] ? $line : '<error>'.$line.'</error>');
        $this->closeRun($run, null === $summary['error'] ? PollRun::STATUS_OK : PollRun::STATUS_ERROR, $line);

        return null === $summary['error'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array{due: int, calls: int, ok: int, failed: int, skipped: int, left: int, error: ?string} $summary
     */
    public static function summaryLine(array $summary): string
    {
        $line = sprintf('due %d, calls %d, ok %d, failed %d, skipped %d, left %d', $summary['due'], $summary['calls'], $summary['ok'], $summary['failed'], $summary['skipped'], $summary['left']);

        return null === $summary['error'] ? $line : $line.'; error: '.$summary['error'];
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

    private function closeRun(?PollRun $run, string $status, string $summary): void
    {
        if (null === $run) {
            return;
        }

        $run->finish($status, $summary, new \DateTimeImmutable());
        $this->runs->saveEntity($run);
    }
}
