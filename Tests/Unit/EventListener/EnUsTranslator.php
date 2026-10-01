<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Translator for tests that serves the plugin's real en_US messages (Translations/en_US/messages.ini), so a test
 * asserts the text the user would read in English, and a missing or misspelled key shows up as the key itself.
 */
final class EnUsTranslator implements TranslatorInterface
{
    /**
     * @var array<string, string>|null
     */
    private static ?array $messages = null;

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        self::$messages ??= self::load();

        return strtr(self::$messages[$id] ?? $id, $parameters);
    }

    public function getLocale(): string
    {
        return 'en_US';
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        $messages = [];

        foreach (file(__DIR__.'/../../../Translations/en_US/messages.ini', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (1 === preg_match('/^([A-Za-z0-9_.\-]+)="(.*)"\s*$/', $line, $match)) {
                $messages[$match[1]] = $match[2];
            }
        }

        return $messages;
    }
}
