<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

/**
 * Serialized into a payload to prove a reader never builds objects from stored data: unserializing it
 * with allowed_classes would run __wakeup() and set the flag.
 */
final class WakeupProbe
{
    public static bool $woke = false;

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}
