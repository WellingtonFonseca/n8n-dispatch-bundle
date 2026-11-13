<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\SettingValue;
use PHPUnit\Framework\TestCase;

class SettingValueTest extends TestCase
{
    public function testFlagIsOnOnlyForOneTrueOrOneAsText(): void
    {
        $this->assertTrue(SettingValue::flag(1));
        $this->assertTrue(SettingValue::flag('1'));
        $this->assertTrue(SettingValue::flag(true));
        $this->assertFalse(SettingValue::flag(0));
        $this->assertFalse(SettingValue::flag('0'));
        $this->assertFalse(SettingValue::flag(null));
        $this->assertFalse(SettingValue::flag(''));
        // text that is not a whole 1 reads as off, as the cast to int did before this class existed
        $this->assertFalse(SettingValue::flag('abc'));
        $this->assertFalse(SettingValue::flag('0.0'));
    }

    public function testClampKeepsTheValueInsideTheRange(): void
    {
        $this->assertSame(5, SettingValue::clamp(1, 5, 10, 7));
        $this->assertSame(10, SettingValue::clamp('99', 5, 10, 7));
        $this->assertSame(8, SettingValue::clamp('8', 5, 10, 7));
        $this->assertSame(7, SettingValue::clamp('abc', 5, 10, 7));
        $this->assertSame(7, SettingValue::clamp(null, 5, 10, 7));
    }
}
