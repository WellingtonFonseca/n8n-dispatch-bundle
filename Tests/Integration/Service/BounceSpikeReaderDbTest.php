<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Service\Alert\BounceSpikeReader;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The query of BounceSpikeReader against the stack's real MySQL, in a transaction rolled back at the end. Every campaign
 * is made by the test, and the result is narrowed to its own campaign id.
 */
class BounceSpikeReaderDbTest extends RollbackDatabaseTestCase
{
    private const SINCE = '2026-03-10 10:00:00';

    private BounceSpikeReader $reader;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->reader = new BounceSpikeReader($em);
        $this->unique = 'ZZ-bounce-'.microtime(true).'-'.random_int(1000, 9999);
    }

    private function campaign(bool $published = true): int
    {
        return $this->insert('campaigns', ['name' => $this->unique, 'is_published' => $published ? 1 : 0, 'allow_restart' => 0]);
    }

    private function step(int $campaignId, string $type = 'n8ndispatch.email.send'): int
    {
        return $this->insert('campaign_events', [
            'campaign_id' => $campaignId, 'name' => $type, 'type' => $type, 'event_type' => 'action',
            'event_order' => 1, 'properties' => serialize([]), 'failed_count' => 0,
        ]);
    }

    private function lead(): int
    {
        return $this->insert('leads', ['is_published' => 1, 'points' => 0, 'email' => $this->unique.random_int(1, 1000000).'@example.test']);
    }

    private function sendTo(int $campaignId, int $stepId, int $leadId, string $at = '2026-03-10 11:00:00', int $scheduled = 0, ?int $nonAction = 0, int $rotation = 1): int
    {
        return $this->insert('campaign_lead_event_log', [
            'event_id' => $stepId, 'campaign_id' => $campaignId, 'lead_id' => $leadId, 'rotation' => $rotation, 'is_scheduled' => $scheduled,
            'system_triggered' => 0, 'date_triggered' => $at, 'non_action_path_taken' => $nonAction,
        ]);
    }

    private function dnc(int $leadId, int $reason = 2, string $channel = 'email', string $at = '2026-03-10 11:30:00'): void
    {
        $this->insert('lead_donotcontact', ['lead_id' => $leadId, 'date_added' => $at, 'reason' => $reason, 'channel' => $channel]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offenders(int $minSends, int $percent): array
    {
        return array_values(array_filter(
            $this->reader->offenders(new \DateTimeImmutable(self::SINCE), $minSends, $percent),
            fn (array $row): bool => $row['name'] === $this->unique
        ));
    }

    /**
     * @return array{int, int} the campaign and its Email step
     */
    private function campaignWithSends(int $sends, int $bounces): array
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);

        for ($i = 0; $i < $sends; ++$i) {
            $lead = $this->lead();
            $this->sendTo($campaign, $step, $lead);

            if ($i < $bounces) {
                $this->dnc($lead);
            }
        }

        return [$campaign, $step];
    }

    public function testACampaignOverThePercentWithEnoughSendsIsReportedWithItsNumbers(): void
    {
        [$campaign] = $this->campaignWithSends(10, 3);

        $this->assertSame([['campaign_id' => $campaign, 'name' => $this->unique, 'sends' => 10, 'bounces' => 3, 'percent' => 30.0]], $this->offenders(10, 30));
    }

    public function testJustUnderThePercentIsNotReportedAndExactlyAtItIs(): void
    {
        $this->campaignWithSends(10, 2);

        $this->assertSame([], $this->offenders(10, 21));
        $this->assertCount(1, $this->offenders(10, 20));
    }

    public function testBelowTheMinimumOfSendsTheCampaignIsNotChecked(): void
    {
        $this->campaignWithSends(9, 9);

        $this->assertSame([], $this->offenders(10, 1));
        $this->assertCount(1, $this->offenders(9, 1));
    }

    public function testAnUnpublishedCampaignIsLeftOut(): void
    {
        $campaign = $this->campaign(false);
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $this->sendTo($campaign, $step, $lead);
        $this->dnc($lead);

        $this->assertSame([], $this->offenders(1, 1));
    }

    public function testOnlyTheEmailStepCounts(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign, 'n8ndispatch.sms.send');
        $lead     = $this->lead();
        $this->sendTo($campaign, $step, $lead);
        $this->dnc($lead);

        $this->assertSame([], $this->offenders(1, 1));
    }

    public function testOnlyABounceOfTheEmailChannelCounts(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $a        = $this->lead();
        $b        = $this->lead();
        $c        = $this->lead();
        foreach ([$a, $b, $c] as $lead) {
            $this->sendTo($campaign, $step, $lead);
        }
        $this->dnc($a, 1); // unsubscribed
        $this->dnc($b, 3); // manual
        $this->dnc($c, 2, 'sms');

        $this->assertSame([], $this->offenders(1, 1));
    }

    public function testASendOrABounceOutsideTheWindowDoesNotCount(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $old      = $this->lead();
        $this->sendTo($campaign, $step, $old, '2026-03-10 09:59:59');
        $this->dnc($old, 2, 'email', '2026-03-10 11:00:00');
        $late = $this->lead();
        $this->sendTo($campaign, $step, $late, '2026-03-10 11:00:00');
        $this->dnc($late, 2, 'email', '2026-03-10 09:59:59');

        $this->assertSame([], $this->offenders(1, 1));
    }

    public function testAScheduledStepAndAStepOfTheOtherPathDoNotCountAsSends(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $a        = $this->lead();
        $b        = $this->lead();
        $this->sendTo($campaign, $step, $a, '2026-03-10 11:00:00', 1);
        $this->sendTo($campaign, $step, $b, '2026-03-10 11:00:00', 0, 1);
        $this->dnc($a);
        $this->dnc($b);

        $this->assertSame([], $this->offenders(1, 1));
    }

    public function testAStepThatCoreLoggedAsFailedDidNotReachTheContact(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $log      = $this->sendTo($campaign, $step, $lead);
        $this->insert('campaign_lead_event_failed_log', ['log_id' => $log, 'date_added' => '2026-03-10 11:00:00', 'reason' => 'x']);
        $this->dnc($lead);

        $this->assertSame([], $this->offenders(1, 1));
    }

    public function testAContactCountsOnceEvenWithTwoSendsAndTwoBounces(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $this->sendTo($campaign, $step, $lead);
        $this->sendTo($campaign, $step, $lead, '2026-03-10 12:00:00', 0, 0, 2);
        $this->dnc($lead);
        $this->dnc($lead, 2, 'email', '2026-03-10 12:30:00');

        $this->assertSame([['campaign_id' => $campaign, 'name' => $this->unique, 'sends' => 1, 'bounces' => 1, 'percent' => 100.0]], $this->offenders(1, 100));
    }

    public function testTheWindowIsReadInUtcWhateverTheTimezoneOfTheCaller(): void
    {
        [$campaign, $step] = $this->campaignWithSends(10, 3);
        $this->sendTo($campaign, $step, $this->lead(), '2026-03-10 09:30:00'); // before the window in UTC, inside it if read as local time

        $sameInstantInSaoPaulo = new \DateTimeImmutable(self::SINCE.' UTC');
        $sameInstantInSaoPaulo = $sameInstantInSaoPaulo->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        $rows = array_filter($this->reader->offenders($sameInstantInSaoPaulo, 10, 30), fn (array $row): bool => $row['name'] === $this->unique);

        $this->assertSame(10, array_values($rows)[0]['sends']);
    }

    public function testThePercentIsRoundedToOneDecimal(): void
    {
        $this->campaignWithSends(3, 1);

        $this->assertSame(33.3, $this->offenders(3, 33)[0]['percent']);
    }

    public function testABounceBeforeTheStepIsNotABounceOfTheStep(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $this->sendTo($campaign, $step, $lead, '2026-03-10 11:00:00');
        $this->dnc($lead, 2, 'email', '2026-03-10 10:30:00'); // in the window, but before the send

        $this->assertSame([], $this->offenders(1, 1));
    }
}
