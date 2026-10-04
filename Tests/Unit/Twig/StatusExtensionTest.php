<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use Mautic\CoreBundle\Helper\DateTimeHelper;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use MauticPlugin\N8nDispatchBundle\Twig\StatusExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class StatusExtensionTest extends TestCase
{
    private function extension(string $locale): StatusExtension
    {
        $translator = $this->createMockForIntersectionOfInterfaces([TranslatorInterface::class, LocaleAwareInterface::class]);
        $translator->method('getLocale')->willReturn($locale);
        $translator->method('trans')->willReturnCallback(fn (string $id, array $params = []): string => strtr(
            ['mautic.n8ndispatch.dispatch.resend.note.date' => 'Resent on %date%', 'mautic.n8ndispatch.dispatch.resend.note.by' => 'By %email%'][$id] ?? $id,
            $params
        ));

        return new StatusExtension($this->createMock(StatusTracker::class), $translator);
    }

    /**
     * What Mautic itself does with a stored UTC date: shown in its configured
     * local time zone (the `default_timezone` setting, not PHP's), so the
     * tests ask Mautic for that zone instead of assuming one.
     */
    private function local(string $utc, string $format): string
    {
        return (new DateTimeHelper($utc, 'Y-m-d H:i:s', 'UTC'))->toLocalString($format);
    }

    private function utc(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    /**
     * @dataProvider locales
     */
    public function testTheDateFollowsTheSystemLanguage(string $locale, string $phpFormat): void
    {
        $this->assertSame(
            $this->local('2026-10-02 13:35:09', $phpFormat),
            $this->extension($locale)->date($this->utc('2026-10-02 13:35:09'))
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locales(): iterable
    {
        yield 'pt_BR'            => ['pt_BR', 'd/m/Y H:i'];
        yield 'en'               => ['en', 'm/d/Y H:i'];
        yield 'en_US'            => ['en_US', 'm/d/Y H:i'];
        yield 'en_GB'            => ['en_GB', 'd/m/Y H:i'];
        yield 'unknown language' => ['xx', 'd/m/Y H:i'];
    }

    public function testThePatternIsDayFirstInPortugueseAndMonthFirstInEnglish(): void
    {
        // 2 October, early enough that the local zone cannot change the day
        $date = $this->utc('2026-10-02 12:00:00');

        $this->assertStringStartsWith('02/10/2026', $this->extension('pt_BR')->date($date));
        $this->assertStringStartsWith('10/02/2026', $this->extension('en')->date($date));
    }

    public function testThereIsNoMonthNameAndNoSeconds(): void
    {
        $text = $this->extension('pt_BR')->date($this->utc('2026-10-02 13:35:09'));

        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $text);
    }

    public function testADateInAnotherTimeZoneIsTakenAsTheInstantItIs(): void
    {
        $inUtc       = $this->utc('2026-10-02 13:35:00');
        $inSaoPaulo = new \DateTimeImmutable('2026-10-02 10:35:00', new \DateTimeZone('America/Sao_Paulo'));

        $this->assertSame($this->extension('pt_BR')->date($inUtc), $this->extension('pt_BR')->date($inSaoPaulo));
    }

    public function testNothingToShowGivesAnEmptyText(): void
    {
        $this->assertSame('', $this->extension('pt_BR')->date(null));
        $this->assertSame('', $this->extension('pt_BR')->date('not a date'));
    }

    public function testATranslatorWithoutALocaleFallsBackToTheDefaultLanguage(): void
    {
        $extension = new StatusExtension($this->createMock(StatusTracker::class), $this->createMock(TranslatorInterface::class));

        $this->assertSame(
            $this->local('2026-10-02 13:35:00', 'd/m/Y H:i'),
            $extension->date($this->utc('2026-10-02 13:35:00'))
        );
    }

    public function testExposesTheTwigFunctions(): void
    {
        $names = array_map(fn ($f) => $f->getName(), $this->extension('pt_BR')->getFunctions());

        $this->assertContains('n8ndispatch_status', $names);
        $this->assertContains('n8ndispatch_date', $names);
    }

    public function testDispatchTextIsTheStoredJsonWithTheMomentInTheSystemLanguage(): void
    {
        $text = $this->extension('en')->dispatchText([
            'resend'   => ['por' => 'a@b.com', 'em' => '2026-10-03 23:19'],
            'response' => ['error' => 'boom'],
        ]);

        $this->assertSame(
            ['reenvio' => ['por' => 'a@b.com', 'em' => $this->local('2026-10-03 23:19:00', 'm/d/Y H:i')], 'response' => ['error' => 'boom']],
            json_decode($text, true)
        );
        $this->assertStringContainsString("\n    \"reenvio\": {", $text, 'written out, one key per line');
    }

    public function testDispatchTextOfAFirstDispatchHasOnlyTheResponse(): void
    {
        $this->assertSame(['response' => ['ok' => true]], json_decode($this->extension('en')->dispatchText(['resend' => null, 'response' => ['ok' => true]]), true));
        $this->assertSame(['response' => 'Connection refused'], json_decode($this->extension('en')->dispatchText(['resend' => null, 'response' => 'Connection refused']), true));
    }

    public function testDispatchTextWithNothingToShowIsEmpty(): void
    {
        $this->assertSame('', $this->extension('en')->dispatchText(['resend' => null, 'response' => null]));
        $this->assertSame('', $this->extension('en')->dispatchText([]));
    }

    public function testDispatchTextOfAResendWithoutAUserOmitsWho(): void
    {
        $this->assertSame(
            ['reenvio' => ['em' => $this->local('2026-10-03 23:19:00', 'm/d/Y H:i')]],
            json_decode($this->extension('en')->dispatchText(['resend' => ['em' => '2026-10-03 23:19'], 'response' => null]), true)
        );
    }

    public function testHistoryTextOfACallbackIsTheMessageAsJson(): void
    {
        $ext = $this->extension('en');

        $this->assertSame(['message' => 'caixa cheia'], json_decode($ext->historyText(['kind' => 'callback', 'message' => 'caixa cheia']), true));
        $this->assertSame(['message' => ['code' => 400]], json_decode($ext->historyText(['kind' => 'callback', 'message' => ['code' => 400]]), true));
        $this->assertSame(['message' => ['a', 'b']], json_decode($ext->historyText(['kind' => 'callback', 'message' => ['a', 'b']]), true));
        $this->assertSame('', $ext->historyText(['kind' => 'callback', 'message' => null]));
    }

    public function testHistoryTextOfADispatchIsItsStoredJson(): void
    {
        $this->assertSame(['response' => ['ok' => true]], json_decode($this->extension('en')->historyText(['kind' => 'dispatch', 'resend' => null, 'response' => ['ok' => true]]), true));
    }
}
