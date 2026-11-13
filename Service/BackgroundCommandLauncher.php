<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts a scheduled job of the plugin (`<command> --run-id=<id>`: the status
 * poll, the Mirror resync) in the background, so the Mautic cron command that
 * woke the plugin up is not held back by the calls to n8n. Detached with
 * `nohup ... &` because Symfony's Process kills its child when the PHP process
 * ends. Registered in Config/services.php, which gives it the project dir (a
 * plain string cannot be autowired).
 */
class BackgroundCommandLauncher
{
    private string $phpBinary;

    public function __construct(private string $projectDir, ?string $phpBinary = null)
    {
        // PHP_BINARY is the web server's binary under mod_php, hence the finder.
        $this->phpBinary = $phpBinary ?? ((new PhpExecutableFinder())->find() ?: 'php');
    }

    public function buildShellCommand(string $command, int $runId): string
    {
        $arguments = [
            $this->phpBinary,
            $this->projectDir.'/bin/console',
            $command,
            '--run-id='.$runId,
        ];

        return 'nohup '.implode(' ', array_map('escapeshellarg', $arguments)).' >/dev/null 2>&1 &';
    }

    /**
     * @throws \RuntimeException when the shell could not be started
     */
    public function launch(string $command, int $runId): void
    {
        $process = Process::fromShellCommandline($this->buildShellCommand($command, $runId), $this->projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not start '.$command.': '.trim($process->getErrorOutput()));
        }
    }
}
