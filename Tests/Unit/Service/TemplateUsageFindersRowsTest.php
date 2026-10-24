<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/WakeupProbe.php';

/**
 * What the three usage finders' filterRows() share: a step without the
 * template key matches no real template, names and status come out as text
 * whatever the driver returned, and a stored payload never builds objects.
 */
class TemplateUsageFindersRowsTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}> finder, properties key of the template
     */
    public static function finders(): array
    {
        return [
            'email' => [EmailTemplateUsageFinder::class, 'email'],
            'sms'   => [SmsTemplateUsageFinder::class, 'smsTemplate'],
            'hsm'   => [HsmTemplateUsageFinder::class, 'hsmTemplateId'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('finders')]
    public function testAStepWithoutTheTemplateKeyMatchesNoRealTemplate(string $class, string $key): void
    {
        $row = ['event_id' => 1, 'event_name' => 'n', 'properties' => serialize(['status' => 'test']), 'campaign_id' => 1, 'campaign_name' => 'c', 'campaign_published' => 1];

        $this->assertSame([], $class::filterRows([$row], 1));
        $this->assertSame([], $class::filterRows([$row], -1));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('finders')]
    public function testNamesAndStatusAreTextWhateverTheDriverReturned(string $class, string $key): void
    {
        $row = ['event_id' => 1, 'event_name' => 123, 'properties' => serialize([$key => 5, 'status' => 7]), 'campaign_id' => 1, 'campaign_name' => 456, 'campaign_published' => 1];

        $usage = $class::filterRows([$row], 5)[0];

        $this->assertSame('123', $usage['eventName']);
        $this->assertSame('456', $usage['campaignName']);
        $this->assertSame('7', $usage['status']);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('finders')]
    public function testAStoredPayloadNeverBuildsObjects(string $class, string $key): void
    {
        WakeupProbe::$woke = false;
        $properties        = serialize([$key => 5, 'probe' => new WakeupProbe()]);
        WakeupProbe::$woke = false;

        $class::filterRows([['event_id' => 1, 'event_name' => 'n', 'properties' => $properties, 'campaign_id' => 1, 'campaign_name' => 'c', 'campaign_published' => 1]], 5);

        $this->assertFalse(WakeupProbe::$woke);
    }
}
