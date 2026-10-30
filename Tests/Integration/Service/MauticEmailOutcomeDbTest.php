<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Service\MauticEmailOutcome;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The queries of MauticEmailOutcome against the stack's real MySQL: core's Stat, page hits and Do Not Contact tables.
 * Every row is made by the test inside a transaction rolled back at the end.
 */
class MauticEmailOutcomeDbTest extends RollbackDatabaseTestCase
{
    private MauticEmailOutcome $outcome;
    private string $unique;
    private int $leadId;
    private int $emailId;
    private int $redirectId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->outcome = new MauticEmailOutcome($em);

        $this->unique     = 'ZZ-outcome-'.microtime(true).'-'.random_int(1000, 9999);
        $this->leadId     = $this->lead();
        $this->emailId    = $this->insert('emails', ['name' => $this->unique, 'is_published' => 1, 'read_count' => 0, 'sent_count' => 0, 'variant_sent_count' => 0, 'variant_read_count' => 0, 'revision' => 1, 'lang' => 'en', 'headers' => '[]']);
        $this->redirectId = $this->insert('page_redirects', ['is_published' => 1, 'redirect_id' => substr(md5($this->unique), 0, 25), 'url' => 'https://example.test/a', 'hits' => 0, 'unique_hits' => 0]);
    }

    private function lead(): int
    {
        return $this->insert('leads', ['is_published' => 1, 'points' => 0, 'email' => $this->unique.random_int(1, 99999).'@example.test']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function stat(string $sentAt, array $extra = [], ?int $leadId = null): int
    {
        return $this->insert('email_stats', $extra + [
            'email_id' => $this->emailId, 'lead_id' => $leadId ?? $this->leadId, 'email_address' => 'x@example.test', 'date_sent' => $sentAt,
            'is_read' => 0, 'is_failed' => 0, 'viewed_in_browser' => 0, 'retry_count' => 0, 'open_count' => 0, 'source' => 'campaign.event', 'source_id' => 1,
        ]);
    }

    private function hit(string $at, string $url = 'https://example.test/a', ?int $redirect = null, ?int $leadId = null, ?int $emailId = null): int
    {
        return $this->insert('page_hits', ['redirect_id' => $redirect ?? $this->redirectId, 'email_id' => $emailId ?? $this->emailId, 'lead_id' => $leadId ?? $this->leadId, 'date_hit' => $at, 'url' => $url, 'tracking_id' => bin2hex(random_bytes(8)), 'code' => 200]);
    }

    private function dnc(int $reason, string $at, string $channel = 'email', string $comments = ''): int
    {
        return $this->insert('lead_donotcontact', ['lead_id' => $this->leadId, 'date_added' => $at, 'reason' => $reason, 'channel' => $channel, 'comments' => $comments]);
    }

    /**
     * @param list<int> $statIds
     */
    private function state(array $statIds): string
    {
        return $this->outcome->states(['k' => ['lead' => $this->leadId, 'statIds' => $statIds]])['k'];
    }

    public function testWithoutAStatTheEmailWasHeldBack(): void
    {
        $this->assertSame('queued', $this->state([]));
        $this->assertSame('queued', $this->state([999999999]), 'a Stat that is gone');
    }

    public function testWithoutAStatTheStepSaysWhyOrTheEmailWasHeldBack(): void
    {
        $states = $this->outcome->states([
            'failed'  => ['lead' => $this->leadId, 'statIds' => [], 'outcome' => 'failed'],
            'skipped' => ['lead' => $this->leadId, 'statIds' => [], 'outcome' => 'skipped'],
            'queued'  => ['lead' => $this->leadId, 'statIds' => [], 'outcome' => 'queued'],
            'unknown' => ['lead' => $this->leadId, 'statIds' => [], 'outcome' => null],
        ]);

        $this->assertSame(['failed' => 'failed', 'skipped' => 'skipped', 'queued' => 'queued', 'unknown' => 'queued'], $states);
        $this->assertSame('failed', $this->outcome->detail($this->leadId, ['n8ndispatch' => ['mode' => 'mautic', 'outcome' => 'failed']])['state']);
        $this->assertSame('skipped', $this->outcome->detail($this->leadId, ['n8ndispatch' => ['mode' => 'mautic', 'outcome' => 'skipped']])['state']);
    }

    public function testAStatWinsOverTheOutcomeTheStepRecorded(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00', ['is_read' => 1]);

        $this->assertSame('opened', $this->outcome->states(['k' => ['lead' => $this->leadId, 'statIds' => [$stat], 'outcome' => 'queued']])['k']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function late(string $sentAt, array $extra = [], ?int $event = null, ?int $leadId = null): int
    {
        return $this->stat($sentAt, $extra + ['source_id' => $event ?? 55], $leadId);
    }

    /**
     * @return array<string, mixed>
     */
    private function held(?string $outcome = 'queued', string $since = '2026-05-01 10:00:00', int $event = 55, ?int $leadId = null): array
    {
        return ['lead' => $leadId ?? $this->leadId, 'statIds' => [], 'outcome' => $outcome, 'event' => $event, 'since' => $since];
    }

    public function testTheStatAnEmailGotWhenMauticSentItLaterIsFoundByContactStepAndTime(): void
    {
        $this->late('2026-05-01 10:30:00', ['is_read' => 1]);

        $this->assertSame('opened', $this->outcome->states(['k' => $this->held()])['k']);
        $this->assertSame('opened', $this->outcome->detail($this->leadId, ['n8ndispatch' => ['outcome' => 'queued']], 55, '2026-05-01 10:00:00')['state']);
        $this->assertCount(1, $this->outcome->detail($this->leadId, ['n8ndispatch' => ['outcome' => 'queued']], 55, '2026-05-01 10:00:00')['attempts']);
    }

    public function testAStatMadeInTheVerySecondOfTheLogIsItsLateStat(): void
    {
        $this->late('2026-05-01 10:00:00', ['is_read' => 1]);

        $this->assertSame('opened', $this->outcome->states(['k' => $this->held()])['k']);
    }

    public function testTheNewestLateStatIsTheOneThatCounts(): void
    {
        $this->late('2026-05-01 10:30:00', ['is_failed' => 1]);
        $this->late('2026-05-01 11:30:00', ['is_read' => 1]);

        $this->assertSame('opened', $this->outcome->states(['k' => $this->held()])['k']);
    }

    public function testAStatOfBeforeTheLogOfAnotherStepOrAnotherContactIsNotItsLateStat(): void
    {
        $this->late('2026-05-01 09:59:59'); // before the log
        $this->late('2026-05-01 10:30:00', [], 56); // another step
        $this->late('2026-05-01 10:30:00', [], null, $this->lead()); // another contact
        $this->insert('email_stats', ['email_id' => $this->emailId, 'lead_id' => $this->leadId, 'email_address' => 'x@example.test', 'date_sent' => '2026-05-01 10:30:00', 'is_read' => 0, 'is_failed' => 0, 'viewed_in_browser' => 0, 'retry_count' => 0, 'open_count' => 0, 'source' => 'email', 'source_id' => 55]); // not from a campaign step

        $this->assertSame('queued', $this->outcome->states(['k' => $this->held()])['k']);
    }

    public function testADispatchTheStepSaidFailedOrSkippedNeverTakesALateStat(): void
    {
        $this->late('2026-05-01 10:30:00', ['is_read' => 1]);

        $states = $this->outcome->states(['f' => $this->held('failed'), 's' => $this->held('skipped')]);

        $this->assertSame(['f' => 'failed', 's' => 'skipped'], $states);
    }

    public function testADispatchWithItsOwnStatNeverLooksForALateOne(): void
    {
        $own = $this->stat('2026-05-01 10:05:00');
        $this->late('2026-05-01 10:30:00', ['is_read' => 1]);

        $this->assertSame('sent', $this->outcome->states(['k' => ['lead' => $this->leadId, 'statIds' => [$own]] + $this->held()])['k']);
    }

    public function testAHeldDispatchWithoutTheStepOrTheMomentOfItsLogLooksForNothing(): void
    {
        $this->late('2026-05-01 10:30:00', ['is_read' => 1]);

        $this->assertSame('queued', $this->outcome->states(['a' => ['lead' => $this->leadId, 'statIds' => []], 'b' => $this->held('queued', ''), 'c' => $this->held('queued', '2026-05-01 10:00:00', 0)])['a']);
        $this->assertSame('queued', $this->outcome->states(['b' => $this->held('queued', '   ')])['b']);
        $this->assertSame('queued', $this->outcome->states(['c' => $this->held('queued', '2026-05-01 10:00:00', 0)])['c']);
    }

    public function testManyHeldDispatchesFindTheirOwnLateStats(): void
    {
        $other = $this->lead();
        $this->late('2026-05-01 10:30:00', ['is_read' => 1]);
        $this->late('2026-05-01 10:30:00', ['is_failed' => 1], null, $other);

        $states = $this->outcome->states(['ana' => $this->held(), 'beto' => $this->held('queued', '2026-05-01 10:00:00', 55, $other), 'nobody' => $this->held('queued', '2026-05-01 10:00:00', 99)]);

        $this->assertSame(['ana' => 'opened', 'beto' => 'failed', 'nobody' => 'queued'], $states);
    }

    public function testASentEmailThatNobodyReadIsSent(): void
    {
        $this->assertSame('sent', $this->state([$this->stat('2026-05-01 10:00:00')]));
    }

    public function testAReadEmailIsOpenedAndAClickMakesItClicked(): void
    {
        $opened = $this->stat('2026-05-01 10:00:00', ['is_read' => 1, 'date_read' => '2026-05-01 10:05:00', 'open_count' => 2]);
        $this->assertSame('opened', $this->state([$opened]));

        $this->hit('2026-05-01 10:06:00');
        $this->assertSame('clicked', $this->state([$opened]));
    }

    public function testAClickAloneIsClickedEvenIfThePixelNeverAnswered(): void
    {
        $this->hit('2026-05-01 10:06:00');

        $this->assertSame('clicked', $this->state([$this->stat('2026-05-01 10:00:00')]));
    }

    public function testOnlyTheClicksOfThisContactThisEmailAndAfterTheSendCount(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00');
        $this->hit('2026-05-01 09:59:59'); // before the send
        $this->hit('2026-05-01 10:06:00', 'https://example.test/a', null, $this->lead()); // another contact
        $otherEmail = $this->insert('emails', ['name' => $this->unique.' other', 'is_published' => 1, 'read_count' => 0, 'sent_count' => 0, 'variant_sent_count' => 0, 'variant_read_count' => 0, 'revision' => 1, 'lang' => 'en', 'headers' => '[]']);
        $this->hit('2026-05-01 10:06:00', 'https://example.test/a', null, null, $otherEmail); // another Email
        $this->insert('page_hits', ['email_id' => $this->emailId, 'lead_id' => $this->leadId, 'date_hit' => '2026-05-01 10:07:00', 'url' => 'https://example.test/view', 'tracking_id' => 'zz'.random_int(1, 9999999), 'code' => 200]); // a view with no redirect

        $this->assertSame('sent', $this->state([$stat]));
    }

    public function testAFailedSendIsFailed(): void
    {
        $this->assertSame('failed', $this->state([$this->stat('2026-05-01 10:00:00', ['is_failed' => 1])]));
    }

    public function testABounceAfterTheSendWinsOverEverything(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00', ['is_read' => 1]);
        $this->hit('2026-05-01 10:06:00');
        $this->dnc(2, '2026-05-01 11:00:00');
        $this->dnc(1, '2026-05-01 12:00:00');

        $this->assertSame('bounced', $this->state([$stat]));
    }

    public function testAnUnsubscribeAfterTheSendWinsOverAnOpenAndAClick(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00', ['is_read' => 1]);
        $this->hit('2026-05-01 10:06:00');
        $this->dnc(1, '2026-05-01 11:00:00');

        $this->assertSame('unsubscribed', $this->state([$stat]));
    }

    public function testAFailedSendThatAlsoBouncedIsBounced(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00', ['is_failed' => 1]);
        $this->dnc(2, '2026-05-01 10:00:00');

        $this->assertSame('bounced', $this->state([$stat]), 'a Do Not Contact in the very second of the send counts');
    }

    public function testADoNotContactOfBeforeTheSendOfAnotherReasonOrAnotherChannelIsNotThisSends(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00');
        $this->dnc(2, '2026-05-01 09:59:59'); // before
        $this->dnc(3, '2026-05-01 11:00:00'); // manual
        $this->dnc(1, '2026-05-01 11:00:00', 'sms'); // another channel

        $this->assertSame('sent', $this->state([$stat]));
    }

    public function testTheStateIsTheOneOfTheLastAttempt(): void
    {
        $first  = $this->stat('2026-05-01 10:00:00', ['is_failed' => 1]);
        $second = $this->stat('2026-05-02 10:00:00', ['is_read' => 1]);

        $this->assertSame('opened', $this->state([$first, $second]));
        $this->assertSame('opened', $this->state([$second, $first]), 'the newest Stat, not the order of the list');
    }

    public function testManyDispatchesAreReadAtOnce(): void
    {
        $other = $this->lead();
        $a     = $this->stat('2026-05-01 10:00:00', ['is_read' => 1]);
        $b     = $this->stat('2026-05-01 10:00:00', ['is_failed' => 1], $other);

        $states = $this->outcome->states([
            10 => ['lead' => $this->leadId, 'statIds' => [$a]],
            11 => ['lead' => $other, 'statIds' => [$b]],
            12 => ['lead' => $other, 'statIds' => []],
        ]);

        $this->assertSame([10 => 'opened', 11 => 'failed', 12 => 'queued'], $states);
    }

    public function testTheDetailListsTheAttemptsNewestFirstWithTheirOwnOpenAndClicks(): void
    {
        $first  = $this->stat('2026-05-01 10:00:00', ['is_failed' => 1]);
        $second = $this->stat('2026-05-02 10:00:00', ['is_read' => 1, 'date_read' => '2026-05-02 10:05:00', 'open_count' => 3]);
        $this->hit('2026-05-01 10:30:00', 'https://example.test/first'); // between the two sends: the first attempt's
        $this->hit('2026-05-02 10:30:00', 'https://example.test/b');
        $this->hit('2026-05-02 11:30:00', 'https://example.test/b');
        $this->hit('2026-05-02 11:00:00', 'https://example.test/c');

        $detail = $this->outcome->detail($this->leadId, ['n8ndispatch' => ['mode' => 'mautic', 'statIds' => [$first, $second]]]);

        $this->assertSame('clicked', $detail['state']);
        $this->assertSame([$second, $first], array_column($detail['attempts'], 'statId'));
        $this->assertNull($detail['dnc']);

        [$newest, $oldest] = $detail['attempts'];
        $this->assertSame('2026-05-02 10:00:00', $newest['sentAt']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $newest['sentAt']->getTimezone()->getName());
        $this->assertFalse($newest['failed']);
        $this->assertSame('2026-05-02 10:05:00', $newest['openedAt']->format('Y-m-d H:i:s'));
        $this->assertSame(3, $newest['openCount']);
        $this->assertSame(
            [['https://example.test/b', 2, '2026-05-02 11:30:00'], ['https://example.test/c', 1, '2026-05-02 11:00:00']],
            array_map(static fn (array $c): array => [$c['url'], $c['count'], $c['lastAt']->format('Y-m-d H:i:s')], $newest['clicks'])
        );

        $this->assertTrue($oldest['failed']);
        $this->assertNull($oldest['openedAt']);
        $this->assertSame(0, $oldest['openCount']);
        $this->assertSame(
            [['https://example.test/first', 1]],
            array_map(static fn (array $c): array => [$c['url'], $c['count']], $oldest['clicks'])
        );
    }

    public function testAClickInTheVerySecondOfTheNextSendBelongsToTheNextAttempt(): void
    {
        $first  = $this->stat('2026-05-01 10:00:00');
        $second = $this->stat('2026-05-02 10:00:00');
        $this->hit('2026-05-02 10:00:00', 'https://example.test/edge');

        [$newest, $oldest] = $this->outcome->detail($this->leadId, ['n8ndispatch' => ['statIds' => [$first, $second]]])['attempts'];

        $this->assertSame(['https://example.test/edge'], array_column($newest['clicks'], 'url'));
        $this->assertSame([], $oldest['clicks']);
    }

    public function testADoNotContactOfBeforeTheSendThatComesFirstDoesNotHideTheOneAfter(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00');
        $this->dnc(1, '2026-05-01 09:00:00'); // older, listed first
        $this->dnc(2, '2026-05-01 11:00:00');

        $this->assertSame('bounced', $this->state([$stat]));
    }

    public function testTheFirstUnsubscribeAfterTheSendIsTheOneShown(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00');
        $this->dnc(1, '2026-05-01 11:00:00', 'email', 'first');
        $this->dnc(1, '2026-05-01 12:00:00', 'email', 'second');

        $detail = $this->outcome->detail($this->leadId, ['n8ndispatch' => ['statId' => $stat]]);

        $this->assertSame('first', $detail['dnc']['comments']);
    }

    public function testEachDispatchOfAPageGetsItsOwnContactsDoNotContactAndClicks(): void
    {
        $ana   = $this->leadId;
        $beto  = $this->lead();
        $carla = $this->lead();
        $a     = $this->stat('2026-05-01 10:00:00', [], $ana);
        $b     = $this->stat('2026-05-01 10:00:00', [], $beto);
        $c     = $this->stat('2026-05-01 10:00:00', [], $carla);
        $this->insert('lead_donotcontact', ['lead_id' => $ana, 'date_added' => '2026-05-01 11:00:00', 'reason' => 1, 'channel' => 'email']);
        $this->insert('lead_donotcontact', ['lead_id' => $beto, 'date_added' => '2026-05-01 11:00:00', 'reason' => 2, 'channel' => 'email']);
        $this->hit('2026-05-01 10:30:00', 'https://example.test/a', null, $carla);
        $this->hit('2026-05-01 10:30:00', 'https://example.test/a', null, $ana);

        $states = $this->outcome->states([
            'ana'   => ['lead' => $ana, 'statIds' => [$a]],
            'beto'  => ['lead' => $beto, 'statIds' => [$b]],
            'carla' => ['lead' => $carla, 'statIds' => [$c]],
        ]);

        $this->assertSame(['ana' => 'unsubscribed', 'beto' => 'bounced', 'carla' => 'clicked'], $states);
    }

    public function testTheDetailShowsTheUnsubscribeOrBounceOfAfterTheLastSend(): void
    {
        $stat = $this->stat('2026-05-01 10:00:00');
        $this->dnc(1, '2026-05-01 11:00:00', 'email', 'Contact unsubscribed via link');

        $detail = $this->outcome->detail($this->leadId, ['n8ndispatch' => ['statId' => $stat]]);

        $this->assertSame('unsubscribed', $detail['state']);
        $this->assertSame('unsubscribed', $detail['dnc']['state']);
        $this->assertSame('2026-05-01 11:00:00', $detail['dnc']['at']->format('Y-m-d H:i:s'));
        $this->assertSame('Contact unsubscribed via link', $detail['dnc']['comments']);

        $this->dnc(2, '2026-05-01 12:00:00', 'email', 'bounce');
        $this->assertSame('bounced', $this->outcome->detail($this->leadId, ['n8ndispatch' => ['statId' => $stat]])['dnc']['state']);
    }

    public function testTheDetailOfADispatchWithoutAStatIsQueuedWithNoAttempts(): void
    {
        $detail = $this->outcome->detail($this->leadId, ['n8ndispatch' => ['mode' => 'mautic', 'statId' => null]]);

        $this->assertSame(['state' => 'queued', 'attempts' => [], 'dnc' => null], $detail);
    }
}
