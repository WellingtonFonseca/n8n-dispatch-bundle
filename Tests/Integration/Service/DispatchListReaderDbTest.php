<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFilters;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The queries of DispatchListReader against the stack's real MySQL. Every row
 * is made by the test inside a transaction that is rolled back at the end
 * (also when the test fails), so nothing is left behind and no seed is read;
 * the lists are narrowed to the test's own campaign.
 */
class DispatchListReaderDbTest extends RollbackDatabaseTestCase
{
    private DispatchListReader $reader;
    private string $unique;
    private int $campaignId;
    private int $emailId;
    private int $smsId;
    private int $hsmId;
    private int $leadId;

    /** @var array<string, int> event type => event id */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->reader = new DispatchListReader($em);

        $this->unique     = 'ZZ-reader-'.microtime(true).'-'.random_int(1000, 9999);
        $this->campaignId = $this->insert('campaigns', ['name' => $this->unique, 'is_published' => 1, 'allow_restart' => 0]);
        $this->insert('campaign_prefix', ['campaign_id' => $this->campaignId, 'prefix' => 'PFX']);

        $this->emailId = $this->email($this->unique.' email');
        $this->smsId   = $this->insert('n8n_dispatch_sms_templates', ['name' => $this->unique.' sms', 'is_published' => 1, 'text' => 't']);
        $this->hsmId   = $this->insert('n8n_dispatch_hsm_templates', ['name' => $this->unique.' hsm', 'is_published' => 1, 'router' => 'r', 'hsm_template' => 'h', 'text' => 't']);
        $this->leadId  = $this->insert('leads', ['is_published' => 1, 'points' => 0, 'firstname' => 'Ana', 'lastname' => 'Silva', 'email' => $this->unique.'@example.test']);

        $this->events = [
            'n8ndispatch.email.send' => $this->event('n8ndispatch.email.send', ['email' => $this->emailId, 'status' => 'production'], 1),
            'n8ndispatch.sms.send'   => $this->event('n8ndispatch.sms.send', ['smsTemplate' => $this->smsId, 'status' => 'test'], 2),
            'n8ndispatch.hsm.send'   => $this->event('n8ndispatch.hsm.send', ['hsmTemplateId' => $this->hsmId], 3),
        ];
    }

    private function email(string $name): int
    {
        return $this->insert('emails', ['name' => $name, 'is_published' => 1, 'read_count' => 0, 'sent_count' => 0, 'variant_sent_count' => 0, 'variant_read_count' => 0, 'revision' => 1, 'lang' => 'en', 'headers' => '[]']);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function event(string $type, array $properties, int $order): int
    {
        return $this->insert('campaign_events', [
            'campaign_id' => $this->campaignId, 'name' => $type, 'type' => $type, 'event_type' => 'action',
            'event_order' => $order, 'properties' => serialize($properties), 'failed_count' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function log(string $type, array $metadata, string $dateTriggered, int $rotation = 1, ?int $leadId = null): int
    {
        return $this->insert('campaign_lead_event_log', [
            'event_id' => $this->events[$type], 'campaign_id' => $this->campaignId, 'lead_id' => $leadId ?? $this->leadId,
            'rotation' => $rotation, 'is_scheduled' => 0, 'system_triggered' => 0,
            'date_triggered' => $dateTriggered, 'metadata' => serialize($metadata),
        ]);
    }

    private function track(string $channel, string $refType, string $refValue, string $outcome): void
    {
        $this->insert('n8n_dispatch_tracking', [
            'channel' => $channel, 'ref_type' => $refType, 'ref_value' => $refValue, 'group_key' => $refValue,
            'outcome' => $outcome, 'dispatched_at' => '2026-01-01 00:00:00', 'check_count' => 0,
        ]);
    }

    /**
     * @return array{int, int, int} the ids of an email, an SMS and an HSM log
     */
    private function threeLogs(): array
    {
        return [
            $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]], '2026-03-10 12:00:00'),
            $this->log('n8ndispatch.sms.send', ['n8ndispatch' => ['status' => 'test']], '2026-03-11 12:00:00'),
            $this->log('n8ndispatch.hsm.send', ['n8ndispatch' => ['status' => 'production']], '2026-03-12 12:00:00'),
        ];
    }

    public function testTheTableListsTheCampaignsDispatchesNewestFirstWithEverythingTheScreenShows(): void
    {
        [$email, $sms, $hsm] = $this->threeLogs();

        $result = $this->reader->search(new DispatchFilters($this->campaignId), 1, 25);

        $this->assertSame(3, $result['total']);
        $this->assertFalse($result['capped']);
        $this->assertSame([$hsm, $sms, $email], array_column($result['items'], 'id'));

        $item = $result['items'][2];
        $this->assertSame('email', $item['channel']);
        $this->assertSame($this->campaignId, $item['campaignId']);
        $this->assertSame('PFX - '.$this->unique, $item['campaignName']);
        $this->assertSame('production', $item['stepStatus']);
        $this->assertSame($this->unique.' email', $item['templateName']);
        $this->assertSame(['name' => 'mautic_email_action', 'params' => ['objectAction' => 'view', 'objectId' => $this->emailId]], $item['templateRoute']);
        $this->assertSame($this->leadId, $item['contactId']);
        $this->assertSame($this->unique.'@example.test', $item['contactEmail']);
        $this->assertSame('Ana Silva', $item['contactName']);
        $this->assertFalse($item['failed']);
        $this->assertSame('2026-03-10 12:00:00', $item['dispatchedAt']->format('Y-m-d H:i:s'));

        $this->assertSame($this->unique.' sms', $result['items'][1]['templateName']);
        $this->assertSame($this->unique.' hsm', $result['items'][0]['templateName']);
        $this->assertSame('mautic_n8ndispatch.hsmtemplate_action', $result['items'][0]['templateRoute']['name']);
    }

    public function testTheOrderIsTheDispatchDateNewestFirstNotTheLogNumber(): void
    {
        // a resent dispatch keeps its log (low id) but gets a new date: it must come first; made in an order unlike the dates
        $newest  = $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['status' => 'production']], '2026-03-20 09:00:00', 1);
        $middle  = $this->log('n8ndispatch.sms.send', ['n8ndispatch' => ['status' => 'test']], '2026-03-15 09:00:00', 2);
        $oldest  = $this->log('n8ndispatch.hsm.send', ['n8ndispatch' => ['status' => 'production']], '2026-03-10 09:00:00', 3);

        $result = $this->reader->search(new DispatchFilters($this->campaignId), 1, 25);

        $this->assertSame([$newest, $middle, $oldest], array_column($result['items'], 'id'));
    }

    public function testTheSameDateFallsBackToTheNewestLogFirst(): void
    {
        $first  = $this->log('n8ndispatch.email.send', ['n8ndispatch' => []], '2026-03-10 09:00:00', 1);
        $second = $this->log('n8ndispatch.sms.send', ['n8ndispatch' => []], '2026-03-10 09:00:00', 2);

        $this->assertSame([$second, $first], array_column($this->reader->search(new DispatchFilters($this->campaignId), 1, 25)['items'], 'id'));
    }

    public function testTheStatusFilterKeepsTheSameOrder(): void
    {
        $recent = $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['httpStatusCode' => 500]], '2026-03-20 09:00:00', 1);
        $old    = $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['httpStatusCode' => 500]], '2026-03-10 09:00:00', 2);

        $page = $this->reader->search(new DispatchFilters($this->campaignId, null, null, null, 'failed'), 1, 25);

        $this->assertSame([$recent, $old], array_column($page['items'], 'id'));
    }

    public function testThePagesCutTheListButNotTheTotal(): void
    {
        [$email, $sms, $hsm] = $this->threeLogs();

        $first  = $this->reader->search(new DispatchFilters($this->campaignId), 1, 2);
        $second = $this->reader->search(new DispatchFilters($this->campaignId), 2, 2);
        $before = $this->reader->search(new DispatchFilters($this->campaignId), 0, 2);

        $this->assertSame([$hsm, $sms], array_column($first['items'], 'id'));
        $this->assertSame([$email], array_column($second['items'], 'id'));
        $this->assertSame([3, 3, 3], [$first['total'], $second['total'], $before['total']]);
        $this->assertSame([$hsm, $sms], array_column($before['items'], 'id'));
    }

    public function testAnotherCampaignHasNothingOfOurs(): void
    {
        $this->threeLogs();

        $this->assertSame(['items' => [], 'total' => 0, 'capped' => false], $this->reader->search(new DispatchFilters($this->campaignId + 1000000), 1, 25));
    }

    public function testTheTemplateFilterKeepsOnlyTheStepsThatSendWithIt(): void
    {
        [, $sms] = $this->threeLogs();

        $mine = $this->reader->search(new DispatchFilters($this->campaignId, ['sms', $this->smsId]), 1, 25);
        $none = $this->reader->search(new DispatchFilters($this->campaignId, ['sms', $this->smsId + 1000000]), 1, 25);
        $wrongChannel = $this->reader->search(new DispatchFilters($this->campaignId, ['email', $this->smsId]), 1, 25);
        $unknown = $this->reader->search(new DispatchFilters($this->campaignId, ['fax', $this->smsId]), 1, 25);

        $this->assertSame([$sms], array_column($mine['items'], 'id'));
        $this->assertSame(1, $mine['total']);
        $this->assertSame([0, 0, 0], [$none['total'], $wrongChannel['total'], $unknown['total']]);
        $this->assertSame([], $none['items']);
    }

    public function testTheDateRangeIncludesItsLimits(): void
    {
        [$email, $sms, $hsm] = $this->threeLogs();
        $utc = new \DateTimeZone('UTC');

        $from = $this->reader->search(new DispatchFilters($this->campaignId, null, new \DateTimeImmutable('2026-03-11 12:00:00', $utc)), 1, 25);
        $to   = $this->reader->search(new DispatchFilters($this->campaignId, null, null, new \DateTimeImmutable('2026-03-11 12:00:00', $utc)), 1, 25);
        $both = $this->reader->search(new DispatchFilters($this->campaignId, null, new \DateTimeImmutable('2026-03-11 12:00:00', $utc), new \DateTimeImmutable('2026-03-11 12:00:00', $utc)), 1, 25);

        $this->assertSame([$hsm, $sms], array_column($from['items'], 'id'));
        $this->assertSame([$sms, $email], array_column($to['items'], 'id'));
        $this->assertSame([$sms], array_column($both['items'], 'id'));
    }

    public function testTheStatusFilterReadsTheCallbackOfEachChannelAndTheFailedCall(): void
    {
        $k = $this->unique;

        $emailPending = $this->log('n8ndispatch.email.send', ['logSendEmailId' => $k.'e-1', 'n8ndispatch' => []], '2026-03-10 10:00:00', 1);
        $emailSuccess = $this->log('n8ndispatch.email.send', ['logSendEmailId' => $k.'e-2', 'n8ndispatch' => []], '2026-03-10 11:00:00', 2);
        $smsError     = $this->log('n8ndispatch.sms.send', ['logSendSmsId' => $k.'s-1', 'n8ndispatch' => []], '2026-03-10 12:00:00', 3);
        $hsmSuccess   = $this->log('n8ndispatch.hsm.send', ['logSendHsmId' => $k.'h-1', 'logSendHsmUuid' => $k.'u-1', 'n8ndispatch' => []], '2026-03-10 13:00:00', 4);
        $refused      = $this->log('n8ndispatch.email.send', ['logSendEmailId' => $k.'e-3', 'n8ndispatch' => ['httpStatusCode' => 500]], '2026-03-10 14:00:00', 5);
        $this->log('n8ndispatch.sms.send', ['n8ndispatch' => ['status' => 'production']], '2026-03-10 15:00:00', 6);

        $this->track('email', 'logSendEmailId', $k.'e-1', 'pending');
        $this->track('email', 'logSendEmailId', $k.'e-2', 'success');
        $this->track('sms', 'logSendSmsId', $k.'s-1', 'error');
        // HSM shows Meta's callback (the uuid), not the Mirror's id.
        $this->track('hsm', 'logSendHsmId', $k.'h-1', 'error');
        $this->track('hsm', 'logSendHsmUuid', $k.'u-1', 'success');
        $this->track('email', 'logSendEmailId', $k.'e-3', 'success');

        $ids = static fn (array $result): array => array_column($result['items'], 'id');
        $by  = fn (string $status): array => $this->reader->search(new DispatchFilters($this->campaignId, null, null, null, $status), 1, 25);

        $this->assertSame([$emailPending], $ids($by('pending')));
        $this->assertSame([$hsmSuccess, $emailSuccess], $ids($by('success')));
        $this->assertSame([$smsError], $ids($by('error')));
        $this->assertSame([$refused], $ids($by('failed')));
        $this->assertSame(2, $by('success')['total']);
        $this->assertFalse($by('success')['capped']);
    }

    public function testTheStatusFilterIsPagedAfterTheFiltering(): void
    {
        $first  = $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['httpStatusCode' => 500]], '2026-03-10 10:00:00', 1);
        $second = $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['httpStatusCode' => 500]], '2026-03-10 11:00:00', 2);
        $third  = $this->log('n8ndispatch.email.send', ['n8ndispatch' => ['httpStatusCode' => 500]], '2026-03-10 12:00:00', 3);

        $page = $this->reader->search(new DispatchFilters($this->campaignId, null, null, null, 'failed'), 2, 2);

        $this->assertSame(3, $page['total']);
        $this->assertSame([$first], array_column($page['items'], 'id'));
        $this->assertNotContains($second, array_column($page['items'], 'id'));
        $this->assertNotContains($third, array_column($page['items'], 'id'));
    }

    public function testAStepWhoseTemplateIsGoneShowsItsNumberAndNoLink(): void
    {
        $gone = $this->event('n8ndispatch.sms.send', ['smsTemplate' => 999999999], 4);
        $log  = $this->insert('campaign_lead_event_log', [
            'event_id' => $gone, 'campaign_id' => $this->campaignId, 'lead_id' => $this->leadId, 'rotation' => 1,
            'is_scheduled' => 0, 'system_triggered' => 0, 'date_triggered' => '2026-03-10 12:00:00', 'metadata' => serialize(['n8ndispatch' => []]),
        ]);

        $item = $this->reader->find($log);

        $this->assertSame('#999999999', $item['templateName']);
        $this->assertNull($item['templateRoute']);
    }

    public function testFindReadsOneDispatchAndNothingForOtherLogs(): void
    {
        [$email] = $this->threeLogs();
        $other   = $this->insert('campaign_events', ['campaign_id' => $this->campaignId, 'name' => 'other', 'type' => 'campaign.sendemail', 'event_type' => 'action', 'event_order' => 9, 'properties' => 'a:0:{}', 'failed_count' => 0]);
        $noMark  = $this->insert('campaign_lead_event_log', [
            'event_id' => $this->events['n8ndispatch.sms.send'], 'campaign_id' => $this->campaignId, 'lead_id' => $this->leadId, 'rotation' => 7,
            'is_scheduled' => 0, 'system_triggered' => 0, 'metadata' => serialize(['x' => 1]),
        ]);
        $otherLog = $this->insert('campaign_lead_event_log', [
            'event_id' => $other, 'campaign_id' => $this->campaignId, 'lead_id' => $this->leadId, 'rotation' => 1,
            'is_scheduled' => 0, 'system_triggered' => 0, 'metadata' => serialize(['n8ndispatch' => []]),
        ]);

        $this->assertSame($email, $this->reader->find($email)['id']);
        $this->assertNull($this->reader->find($noMark));
        $this->assertNull($this->reader->find($otherLog));
        $this->assertNull($this->reader->find(0));
    }

    public function testTheFilterBarOffersOurCampaignAndTemplatesByChannel(): void
    {
        $options = $this->reader->filterOptions();

        $this->assertSame('PFX - '.$this->unique, $options['campaigns'][$this->campaignId]);
        $this->assertSame($this->unique.' email', $options['templates']['email']['email:'.$this->emailId]);
        $this->assertSame($this->unique.' sms', $options['templates']['sms']['sms:'.$this->smsId]);
        $this->assertSame($this->unique.' hsm', $options['templates']['hsm']['hsm:'.$this->hsmId]);

        $names = $options['campaigns'];
        $sorted = $names;
        asort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame(array_keys($sorted), array_keys($names));
    }

    public function testACampaignWithoutPrefixKeepsItsNameInTheList(): void
    {
        $this->db->delete('campaign_prefix', ['campaign_id' => $this->campaignId]);
        $this->threeLogs();

        $this->assertSame($this->unique, $this->reader->search(new DispatchFilters($this->campaignId), 1, 25)['items'][0]['campaignName']);
    }

    public function testTheContactEmailIsTrimmed(): void
    {
        $log = $this->threeLogs()[0];
        $this->db->update('leads', ['email' => '  '.$this->unique.'@example.test  '], ['id' => $this->leadId]);

        $this->assertSame($this->unique.'@example.test', $this->reader->find($log)['contactEmail']);
    }

    public function testTheTemplateFilterFindsEveryStepThatUsesTheTemplate(): void
    {
        $second = $this->event('n8ndispatch.sms.send', ['smsTemplate' => $this->smsId], 5);
        $first  = $this->log('n8ndispatch.sms.send', ['n8ndispatch' => []], '2026-03-10 10:00:00');
        $other  = $this->insert('campaign_lead_event_log', [
            'event_id' => $second, 'campaign_id' => $this->campaignId, 'lead_id' => $this->leadId, 'rotation' => 1,
            'is_scheduled' => 0, 'system_triggered' => 0, 'date_triggered' => '2026-03-10 11:00:00', 'metadata' => serialize(['n8ndispatch' => []]),
        ]);

        $result = $this->reader->search(new DispatchFilters($this->campaignId, ['sms', $this->smsId]), 1, 25);

        $this->assertSame([$other, $first], array_column($result['items'], 'id'));
    }

    public function testTwoTemplatesOfTheSameChannelBothGetTheirName(): void
    {
        $secondEmail = $this->email($this->unique.' email 2');
        $this->events['second'] = $this->event('n8ndispatch.email.send', ['email' => $secondEmail], 6);
        $one = $this->log('n8ndispatch.email.send', ['n8ndispatch' => []], '2026-03-10 10:00:00');
        $two = $this->log('second', ['n8ndispatch' => []], '2026-03-10 11:00:00');

        $names = array_column($this->reader->search(new DispatchFilters($this->campaignId), 1, 25)['items'], 'templateName', 'id');

        $this->assertSame([$two => $this->unique.' email 2', $one => $this->unique.' email'], $names);
    }

    public function testTheFilterBarSortsNamesIgnoringCaseAndByNumber(): void
    {
        foreach (['n10', 'B', 'a', 'n2'] as $i => $suffix) {
            $campaign = $this->insert('campaigns', ['name' => $this->unique.' '.$suffix, 'is_published' => 1, 'allow_restart' => 0]);
            $email    = $this->email($this->unique.' '.$suffix);
            $this->insert('campaign_events', [
                'campaign_id' => $campaign, 'name' => 'e', 'type' => 'n8ndispatch.email.send', 'event_type' => 'action',
                'event_order' => 1, 'properties' => serialize(['email' => $email]), 'failed_count' => 0,
            ]);
        }

        $options = $this->reader->filterOptions();
        $mine    = static fn (array $names): array => array_values(array_filter($names, static fn (string $name): bool => str_contains($name, 'ZZ-reader-') && !str_ends_with($name, 'email')));
        $suffixes = static fn (array $names): array => array_map(static fn (string $name): string => substr($name, (int) strrpos($name, ' ') + 1), $names);

        $this->assertSame(['a', 'B', 'n2', 'n10'], array_slice($suffixes(array_filter($mine($options['campaigns']), fn (string $n): bool => str_contains($n, $this->unique.' '))), 0, 4));
        $emails = array_filter($options['templates']['email'], fn (string $n): bool => str_starts_with($n, $this->unique.' ') && !str_ends_with($n, ' email'));
        $this->assertSame(['a', 'B', 'n2', 'n10'], $suffixes(array_values($emails)));
    }

    public function testTheStatusScanStopsAtTheLimitAndSaysSo(): void
    {
        $limit = DispatchListReader::STATUS_SCAN_LIMIT;
        $this->db->executeStatement('SET SESSION cte_max_recursion_depth = 10000');
        $this->db->executeStatement(
            'INSERT INTO campaign_lead_event_log (event_id, campaign_id, lead_id, rotation, is_scheduled, system_triggered, date_triggered, metadata) '
            .'WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < :count) '
            .'SELECT :event, :campaign, :lead, i, 0, 0, :date, :metadata FROM n',
            ['count' => $limit + 1, 'event' => $this->events['n8ndispatch.email.send'], 'campaign' => $this->campaignId, 'lead' => $this->leadId, 'date' => '2026-03-10 12:00:00', 'metadata' => serialize(['failed' => 1, 'n8ndispatch' => []])]
        );

        $over = $this->reader->search(new DispatchFilters($this->campaignId, null, null, null, 'failed'), 1, 25);

        $this->assertTrue($over['capped']);
        $this->assertSame($limit, $over['total']);
        $this->assertCount(25, $over['items']);

        $this->db->executeStatement('DELETE FROM campaign_lead_event_log WHERE campaign_id = ? AND rotation = ?', [$this->campaignId, $limit + 1]);

        $exact = $this->reader->search(new DispatchFilters($this->campaignId, null, null, null, 'failed'), 1, 25);

        $this->assertFalse($exact['capped']);
        $this->assertSame($limit, $exact['total']);
    }
}
