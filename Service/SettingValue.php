<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * How a value stored by the Features form becomes a setting: the form can store a number as text, a switch as 0, '0'
 * or false, and an old instance may store nothing at all.
 */
final class SettingValue
{
    /** A whole number kept between $min and $max; $default when the value is not a number. */
    public static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /** A switch is on when the stored value, read as a whole number, is not 0; text that is not a number is off. */
    public static function flag(mixed $value): bool
    {
        return (bool) (int) $value;
    }
}
