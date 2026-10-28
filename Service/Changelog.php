<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The plugin's CHANGELOG.md, read for the plugin screen: one block per day, newest first.
 * Format: a "## YYYY-MM-DD" line, then one "- " line per change. Anything else is ignored,
 * and a change outside a dated day (before the first date, or under another "##" heading) is ignored too.
 */
class Changelog
{
    public const FILE = __DIR__.'/../CHANGELOG.md';

    /**
     * @return list<array{date: string, items: list<string>}>
     */
    public static function days(string $path = self::FILE): array
    {
        $text = is_readable($path) ? file_get_contents($path) : false;

        return false === $text ? [] : self::parse($text);
    }

    /**
     * @return list<array{date: string, items: list<string>}>
     */
    public static function parse(string $markdown): array
    {
        $days   = [];
        $isOpen = false;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (1 === preg_match('/^##\s+(\d{4}-\d{2}-\d{2})\s*$/', $line, $match)) {
                $days[] = ['date' => $match[1], 'items' => []];
                $isOpen = true;

                continue;
            }

            // any other "##" heading (e.g. "## Unreleased") ends the day: its changes are not the previous day's
            if (1 === preg_match('/^##(?!#)/', $line)) {
                $isOpen = false;

                continue;
            }

            if ($isOpen && 1 === preg_match('/^-\s+(.+?)\s*$/', $line, $match)) {
                $days[array_key_last($days)]['items'][] = $match[1];
            }
        }

        return $days;
    }
}
