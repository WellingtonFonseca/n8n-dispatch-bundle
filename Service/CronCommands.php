<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The cron commands every Mautic install runs for its campaigns and segments. The plugin rides on them instead of asking
 * for a cron entry of its own (the status poll and the bounce check).
 */
final class CronCommands
{
    public const NAMES = [
        'mautic:campaigns:trigger',
        'mautic:campaigns:update',
        'mautic:segments:update',
    ];
}
