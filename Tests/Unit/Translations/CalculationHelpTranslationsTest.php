<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Translations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list that explains the Calculation box is printed by the twig of the Email tab (messages.ini) and built by
 * n8ndispatch-shared.js for the SMS and HSM forms (javascript.ini): two files, so the texts must stay equal.
 */
class CalculationHelpTranslationsTest extends TestCase
{
    private const KEYS = ['title', 'negative', 'date', 'number', 'decimal', 'whole'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'en_US' => ['en_US'];
        yield 'pt_BR' => ['pt_BR'];
    }

    #[DataProvider('languages')]
    public function testTheJavaScriptTextsAreTheOnesOfTheEmailTab(string $language): void
    {
        $dir      = dirname(__DIR__, 3).'/Translations/'.$language;
        $messages = parse_ini_file($dir.'/messages.ini');
        $script   = parse_ini_file($dir.'/javascript.ini');

        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey('mautic.n8ndispatch.email.tab.calculation.'.$key, $messages);
            $this->assertSame(
                $messages['mautic.n8ndispatch.email.tab.calculation.'.$key],
                $script['mautic.n8ndispatch.js.calculation.'.$key] ?? null,
                $language.' '.$key
            );
        }
    }
}
