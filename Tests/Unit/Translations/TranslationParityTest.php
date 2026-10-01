<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Translations;

use PHPUnit\Framework\TestCase;

/**
 * Every text the plugin has in English must exist in pt_BR too, with the same placeholders, so a new text can't
 * ship in English only (which is how the whole Campaigns tab and the template screens stayed English in pt_BR).
 */
class TranslationParityTest extends TestCase
{
    private const DIR = __DIR__.'/../../../Translations';

    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        foreach (['messages', 'flashes', 'validators', 'javascript'] as $file) {
            yield $file => [$file];
        }
    }

    /**
     * @dataProvider files
     */
    public function testEveryEnglishKeyHasAPortugueseTextWithTheSamePlaceholders(string $file): void
    {
        $english    = $this->load(self::DIR.'/en_US/'.$file.'.ini');
        $portuguese = $this->load(self::DIR.'/pt_BR/'.$file.'.ini');

        $this->assertNotEmpty($english, $file.'.ini has no English texts');
        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($portuguese))), 'keys missing in pt_BR/'.$file.'.ini');
        $this->assertSame([], array_values(array_diff(array_keys($portuguese), array_keys($english))), 'keys only in pt_BR/'.$file.'.ini');

        foreach ($english as $key => $text) {
            $this->assertSame($this->placeholders($text), $this->placeholders($portuguese[$key]), 'placeholders differ for '.$key);
        }
    }

    /**
     * @return array<string, string>
     */
    private function load(string $path): array
    {
        $texts = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (1 === preg_match('/^([A-Za-z0-9_.\-]+)="(.*)"\s*$/', $line, $match)) {
                $texts[$match[1]] = $match[2];
            }
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\w+%/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }
}
