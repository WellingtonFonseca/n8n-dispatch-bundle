<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use PHPUnit\Framework\TestCase;

/**
 * The matching half of HsmTemplateUsageFinder, as in SmsTemplateUsageFinderTest:
 * which "Send via n8n (HSM)" step rows point at a template ('hsmTemplateId').
 * The rows query is checked in Tests/Integration.
 */
class HsmTemplateUsageFinderTest extends TestCase
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

    public function testMatchesTheTemplateIdStoredAsIntOrString(): void
    {
        $usages = HsmTemplateUsageFinder::filterRows([
            $this->row(1, ['hsmTemplateId' => 5, 'status' => 'production']),
            $this->row(2, ['hsmTemplateId' => '5', 'status' => 'paused'], 2, 'Outra', 0),
        ], 5);

        $this->assertSame(
            [
                ['campaignId' => 1, 'campaignName' => 'Campanha', 'campaignPublished' => true, 'eventId' => 1, 'eventName' => 'Passo 1', 'status' => 'production'],
                ['campaignId' => 2, 'campaignName' => 'Outra', 'campaignPublished' => false, 'eventId' => 2, 'eventName' => 'Passo 2', 'status' => 'paused'],
            ],
            $usages
        );
    }

    public function testIgnoresOtherTemplatesAndTheHsmEntitysOwnStringField(): void
    {
        $usages = HsmTemplateUsageFinder::filterRows([
            $this->row(1, ['hsmTemplateId' => 8]),
            $this->row(2, ['hsmTemplate' => 5]),
        ], 5);

        $this->assertSame([], $usages);
    }

    public function testMissingStatusDefaultsToTest(): void
    {
        $this->assertSame('test', HsmTemplateUsageFinder::filterRows([$this->row(1, ['hsmTemplateId' => 5])], 5)[0]['status']);
    }

    public function testUnreadablePropertiesAreSkippedInsteadOfCrashing(): void
    {
        $row               = $this->row(1, []);
        $row['properties'] = 'not serialized';

        $this->assertSame([], HsmTemplateUsageFinder::filterRows([$row], 5));
    }
}
