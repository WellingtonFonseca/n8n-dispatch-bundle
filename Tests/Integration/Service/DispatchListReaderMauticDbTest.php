<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFilters;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use MauticPlugin\N8nDispatchBundle\Service\MauticEmailOutcome;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The Dispatches table and its status filter for an Email sent through Mautic's own mailer: the state comes from Mautic's Stat,
 * page hits and Do Not Contact tables (MauticEmailOutcome), next to the rows of n8n that read their callback as before.
 * Every row is made by the test inside a transaction rolled back at the end.
 */
class DispatchListReaderMauticDbTest extends RollbackDatabaseTestCase
{
    private DispatchListReader $reader;
    private int $campaignId;
    private int $emailId;
    private int $eventId;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->reader = new DispatchListReader($em, new MauticEmailOutcome($em));

        $this->unique     = 'ZZ-mlist-'.microtime(true).'-'.random_int(1000, 9999);
        $this->campaignId = $this->insert('campaigns', ['name' => $this->unique, 'is_published' => 1, 'allow_restart' => 0]);
        $this->emailId    = $this->insert('emails', ['name' => $this->unique, 'is_published' => 1, 'read_count' => 0, 'sent_count' => 0, 'variant_sent_count' => 0, 'variant_read_count' => 0, 'revision' => 1, 'lang' => 'en', 'headers' => '[]']);
        $this->eventId    = $this->insert('campaign_events', [
            'campaign_id' => $this->campaignId, 'name' => 'Email', 'type' => 'n8ndispatch.email.send', 'event_type' => 'action',
            'event_order' => 1, 'properties' => serialize(['email' => $this->emailId, 'status' => 'production']), 'failed_count' => 0,
        ]);
    }

    private function lead(): int
    {
        return $this->insert('leads', ['is_published' => 1, 'points' => 0, 'firstname' => 'Ana', 'email' => $this->unique.random_int(1, 999999).'@example.test']);
    }

    private function stat(int $lead, array $extra = []): int
    {
        return $this->insert('email_stats', $extra + [
            'email_id' => $this->emailId, 'lead_id' => $lead, 'email_address' => 'x@example.test', 'date_sent' => '2026-05-01 10:00:00',
            'is_read' => 0, 'is_failed' => 0, 'viewed_in_browser' => 0, 'retry_count' => 0, 'open_count' => 0, 'source' => 'campaign.event', 'source_id' => $this->eventId,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function log(int $lead, array $metadata, string $date): int
    {
        return $this->insert('campaign_lead_event_log', [
            'event_id' => $this->eventId, 'campaign_id' => $this->campaignId, 'lead_id' => $lead, 'rotation' => 1, 'is_scheduled' => 0,
            'system_triggered' => 0, 'date_triggered' => $date, 'metadata' => serialize($metadata),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function direct(?int $statId, string $outcome, array $extra = []): array
    {
        return ['n8ndispatch' => ['mode' => 'mautic', 'status' => 'production', 'outcome' => $outcome, 'statId' => $statId]] + $extra;
    }

    /**
     * @return array<string, int> the logs by what they are
     */
    private function mixed(): array
    {
        $opened = $this->lead();
        $held   = $this->lead();
        $bounce = $this->lead();
        $failed = $this->lead();
        $n8n    = $this->lead();

        $this->insert('lead_donotcontact', ['lead_id' => $bounce, 'date_added' => '2026-05-01 11:00:00', 'reason' => 2, 'channel' => 'email']);

        return [
            'opened' => $this->log($opened, $this->direct($this->stat($opened, ['is_read' => 1]), 'sent'), '2026-05-01 10:00:00'),
            'held'   => $this->log($held, $this->direct(null, 'queued'), '2026-05-01 10:01:00'),
            'bounce' => $this->log($bounce, $this->direct($this->stat($bounce), 'sent'), '2026-05-01 10:02:00'),
            'failed' => $this->log($failed, $this->direct(null, 'failed', ['failed' => 1, 'reason' => 'N8nDispatch: 550']), '2026-05-01 10:03:00'),
            'n8n'    => $this->log($n8n, ['logSendEmailId' => $this->unique.'e', 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]], '2026-05-01 10:04:00'),
        ];
    }

    public function testEachRowOfMauticCarriesTheStateMauticReportsAndThoseOfN8nCarryNone(): void
    {
        $logs = $this->mixed();

        $items = array_column($this->reader->search(new DispatchFilters($this->campaignId), 1, 25)['items'], 'mauticState', 'id');

        $this->assertSame(
            [$logs['n8n'] => null, $logs['failed'] => 'failed', $logs['bounce'] => 'bounced', $logs['held'] => 'queued', $logs['opened'] => 'opened'],
            $items
        );
    }

    public function testTheStatusFilterReadsTheStateMauticReports(): void
    {
        $logs = $this->mixed();
        $this->insert('n8n_dispatch_tracking', ['channel' => 'email', 'ref_type' => 'logSendEmailId', 'ref_value' => $this->unique.'e', 'group_key' => $this->unique.'e', 'outcome' => 'success', 'dispatched_at' => '2026-05-01 10:04:00', 'check_count' => 0]);

        $ids = fn (string $status): array => array_column($this->reader->search(new DispatchFilters($this->campaignId, null, null, null, $status), 1, 25)['items'], 'id');

        $this->assertSame([$logs['n8n'], $logs['opened']], $ids('success'), 'the Email of n8n by its callback, the one of Mautic by its Stat');
        $this->assertSame([$logs['held']], $ids('pending'));
        $this->assertSame([$logs['bounce']], $ids('error'));
        $this->assertSame([$logs['failed']], $ids('failed'));
    }

    public function testTheDetailsOfADispatchCarryItsState(): void
    {
        $logs = $this->mixed();

        $this->assertSame('bounced', $this->reader->find($logs['bounce'])['mauticState']);
        $this->assertNull($this->reader->find($logs['n8n'])['mauticState']);
    }

    public function testWithoutTheOutcomeReaderEveryRowReadsLikeN8n(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $logs = $this->mixed();

        $items = (new DispatchListReader($em))->search(new DispatchFilters($this->campaignId), 1, 25)['items'];

        $this->assertSame([null], array_values(array_unique(array_column($items, 'mauticState'))));
        $this->assertCount(count($logs), $items);
    }

    public function testTheContactTheStepAndTheMomentOfALogAreFound(): void
    {
        $lead = $this->lead();
        $log  = $this->log($lead, $this->direct(null, 'queued'), '2026-05-01 10:00:00');
        $em   = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $outcome = new MauticEmailOutcome($em);

        $this->assertSame(['lead' => $lead, 'event' => $this->eventId, 'since' => '2026-05-01 10:00:00'], $outcome->factsOfLog($log));
        $this->assertSame(['lead' => 0, 'event' => 0, 'since' => null], $outcome->factsOfLog($log + 1000000));
    }

    public function testAnEmailMauticHeldBackAndSentLaterShowsItsStatInTheTable(): void
    {
        $lead = $this->lead();
        $log  = $this->log($lead, $this->direct(null, 'queued'), '2026-05-01 10:00:00');
        $this->stat($lead, ['date_sent' => '2026-05-01 10:20:00', 'is_read' => 1]);

        $this->assertSame('opened', $this->reader->find($log)['mauticState']);
    }
}
