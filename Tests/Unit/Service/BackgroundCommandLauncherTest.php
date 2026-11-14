<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\BackgroundCommandLauncher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:status-poll')]
#[Group('area:mirror')]
#[Group('area:alerts')]
#[Group('risk:INFRA')]
class BackgroundCommandLauncherTest extends TestCase
{
    public function testBuildsADetachedCommandThatReportsToTheClaimedRun(): void
    {
        $launcher = new BackgroundCommandLauncher('/var/www/html', '/usr/local/bin/php');

        $this->assertSame(
            "nohup '/usr/local/bin/php' '/var/www/html/bin/console' 'n8ndispatch:status:poll' '--run-id=42' >/dev/null 2>&1 &",
            $launcher->buildShellCommand('n8ndispatch:status:poll', 42)
        );
    }
}
