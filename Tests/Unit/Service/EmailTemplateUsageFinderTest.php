<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use PHPUnit\Framework\TestCase;

/**
 * Covers the matching half of EmailTemplateUsageFinder: given the
 * "Send via n8n (Email)" campaign event rows, which ones point at an
 * Email. The rows query itself is plain SQL, checked live. Mirrors
 * SmsTemplateUsageFinderTest, just against the 'email' properties key
 * (Form/Type/EmailDispatchActionType.php's own field) and the
 * 'n8ndispatch.email.send' event type.
 */
class EmailTemplateUsageFinderTest extends TestCase
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

    public function testMatchesEmailIdStoredAsIntOrString(): void
    {
        $usages = EmailTemplateUsageFinder::filterRows([
            $this->row(1, ['email' => 5, 'status' => 'production']),
            $this->row(2, ['email' => '5', 'status' => 'paused'], 2, 'Outra', 0),
        ], 5);

        $this->assertSame(
            [
                ['campaignId' => 1, 'campaignName' => 'Campanha', 'campaignPublished' => true, 'eventId' => 1, 'eventName' => 'Passo 1', 'status' => 'production'],
                ['campaignId' => 2, 'campaignName' => 'Outra', 'campaignPublished' => false, 'eventId' => 2, 'eventName' => 'Passo 2', 'status' => 'paused'],
            ],
            $usages
        );
    }

    public function testIgnoresOtherEmails(): void
    {
        $usages = EmailTemplateUsageFinder::filterRows([
            $this->row(1, ['email' => 8]),
            $this->row(2, ['email' => 5, 'status' => 'test']),
        ], 9);

        $this->assertSame([], $usages);
    }

    public function testMissingStatusDefaultsToTest(): void
    {
        $usages = EmailTemplateUsageFinder::filterRows([$this->row(1, ['email' => 5])], 5);

        $this->assertSame('test', $usages[0]['status']);
    }

    public function testUnreadablePropertiesAreSkippedInsteadOfCrashing(): void
    {
        $row               = $this->row(1, []);
        $row['properties'] = 'not serialized';

        $this->assertSame([], EmailTemplateUsageFinder::filterRows([$row], 5));
    }
}
