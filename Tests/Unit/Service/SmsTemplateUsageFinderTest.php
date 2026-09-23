<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;
use PHPUnit\Framework\TestCase;

/**
 * Covers the matching half of SmsTemplateUsageFinder: given the
 * "Send via n8n (SMS)" campaign event rows, which ones point at a
 * template. The rows query itself is plain SQL, checked live.
 */
class SmsTemplateUsageFinderTest extends TestCase
{
    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function row(int $eventId, array $properties, int $campaignId = 1, string $campaignName = 'Campanha', int $published = 1): array
    {
        return [
            'event_id'           => $eventId,
            'event_name'         => 'Passo '.$eventId,
            'properties'         => serialize($properties),
            'campaign_id'        => $campaignId,
            'campaign_name'      => $campaignName,
            'campaign_published' => $published,
        ];
    }

    public function testMatchesTemplateIdStoredAsIntOrString(): void
    {
        $usages = SmsTemplateUsageFinder::filterRows([
            $this->row(1, ['smsTemplate' => 7, 'status' => 'production']),
            $this->row(2, ['smsTemplate' => '7', 'status' => 'paused'], 2, 'Outra', 0),
        ], 7);

        $this->assertSame(
            [
                ['campaignId' => 1, 'campaignName' => 'Campanha', 'campaignPublished' => true, 'eventId' => 1, 'eventName' => 'Passo 1', 'status' => 'production'],
                ['campaignId' => 2, 'campaignName' => 'Outra', 'campaignPublished' => false, 'eventId' => 2, 'eventName' => 'Passo 2', 'status' => 'paused'],
            ],
            $usages
        );
    }

    public function testIgnoresOtherTemplatesAndLegacyInlineTextEvents(): void
    {
        $usages = SmsTemplateUsageFinder::filterRows([
            $this->row(1, ['smsTemplate' => 8]),
            $this->row(2, ['text' => 'Inline {{foo}}', 'status' => 'test']),
        ], 7);

        $this->assertSame([], $usages);
    }

    public function testMissingStatusDefaultsToTest(): void
    {
        $usages = SmsTemplateUsageFinder::filterRows([$this->row(1, ['smsTemplate' => 7])], 7);

        $this->assertSame('test', $usages[0]['status']);
    }

    public function testUnreadablePropertiesAreSkippedInsteadOfCrashing(): void
    {
        $row               = $this->row(1, []);
        $row['properties'] = 'not serialized';

        $this->assertSame([], SmsTemplateUsageFinder::filterRows([$row], 7));
    }
}
