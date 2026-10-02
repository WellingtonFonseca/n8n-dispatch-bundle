<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\StatusPollLauncher;
use PHPUnit\Framework\TestCase;

class StatusPollLauncherTest extends TestCase
{
    public function testBuildsADetachedCommandThatReportsToTheClaimedRun(): void
    {
        $launcher = new StatusPollLauncher('/var/www/html', '/usr/local/bin/php');

        $this->assertSame(
            "nohup '/usr/local/bin/php' '/var/www/html/bin/console' 'n8ndispatch:status:poll' '--run-id=42' >/dev/null 2>&1 &",
            $launcher->buildShellCommand(42)
        );
    }
}
