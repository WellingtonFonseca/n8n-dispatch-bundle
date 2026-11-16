<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * What every scheduled command (status poll, Mirror resync, failure sweep) does around its own work: read the
 * `--run-id` the scheduler passes, and always close that run, as an error when the work throws (the exception goes on)
 * and as ok or error from the result otherwise. A run left open would show as "running" on the settings screen and
 * block its lane for two hours. A run by hand has no run id: nothing is closed.
 */
class ScheduledRun
{
    public const OPTION = 'run-id';

    public function __construct(private PollRunRepository $runs)
    {
    }

    public static function addOption(Command $command): void
    {
        $command->addOption(self::OPTION, null, InputOption::VALUE_REQUIRED, 'Set by the plugin when it starts a scheduled run: the run to close when this one ends.');
    }

    /** The run to close, or null for a run by hand. */
    public static function id(InputInterface $input): ?int
    {
        $option = $input->getOption(self::OPTION);

        return null !== $option ? (int) $option : null;
    }

    /**
     * @param callable(): array{ok: bool, summary: string} $work    the command's job: its result closes the run
     * @param (callable(int, string): void)|null           $onError for a scheduled run that ends in error (the work threw
     *                                                              or its result is not ok): it gets the run id and the
     *                                                              summary, after the run is closed
     *
     * @return array{ok: bool, summary: string}
     */
    public function run(?int $runId, callable $work, ?callable $onError = null): array
    {
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->close($runId, false, $e->getMessage(), $onError);

            throw $e;
        }

        $this->close($runId, $result['ok'], $result['summary'], $onError);

        return $result;
    }

    private function close(?int $runId, bool $ok, string $summary, ?callable $onError): void
    {
        $this->runs->close($runId, $ok ? PollRun::STATUS_OK : PollRun::STATUS_ERROR, $summary, new \DateTimeImmutable());

        if (!$ok && null !== $runId && null !== $onError) {
            $onError($runId, $summary);
        }
    }
}
