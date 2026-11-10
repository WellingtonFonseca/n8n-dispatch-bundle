<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Mautic\CoreBundle\Helper\CacheStorageHelper;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertThrottle;
use PHPUnit\Framework\TestCase;

class AlertThrottleTest extends TestCase
{
    public function testAFreeKeyIsClaimedForTheCooldown(): void
    {
        $cache = $this->createMock(CacheStorageHelper::class);
        $cache->method('has')->with('n8ndispatch_alert_warning:7')->willReturn(false);
        $cache->expects($this->once())->method('set')->with('n8ndispatch_alert_warning:7', 1, 86400);

        $this->assertTrue((new AlertThrottle($cache))->claim('warning:7', 86400));
    }

    public function testATakenKeyIsRefusedAndNotTakenAgain(): void
    {
        $cache = $this->createMock(CacheStorageHelper::class);
        $cache->method('has')->willReturn(true);
        $cache->expects($this->never())->method('set');

        $this->assertFalse((new AlertThrottle($cache))->claim('warning:7', 86400));
    }

    public function testReleaseFreesTheKey(): void
    {
        $cache = $this->createMock(CacheStorageHelper::class);
        $cache->expects($this->once())->method('delete')->with('n8ndispatch_alert_warning:7');

        (new AlertThrottle($cache))->release('warning:7');
    }
}
