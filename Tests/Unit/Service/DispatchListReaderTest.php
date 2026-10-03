<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use PHPUnit\Framework\TestCase;

/**
 * The pure halves of DispatchListReader: which template a campaign event
 * points at, and how a contact is named. The queries themselves are plain
 * SQL, checked live.
 */
class DispatchListReaderTest extends TestCase
{
    public function testTheEmailEventPointsAtTheEmailKey(): void
    {
        $ref = DispatchListReader::templateRef('n8ndispatch.email.send', serialize(['email' => '12']));

        $this->assertSame(['email', 12], $ref);
    }

    public function testTheSmsEventPointsAtTheSmsTemplateKey(): void
    {
        $ref = DispatchListReader::templateRef('n8ndispatch.sms.send', serialize(['smsTemplate' => 3]));

        $this->assertSame(['sms', 3], $ref);
    }

    public function testTheHsmEventPointsAtTheHsmTemplateIdKey(): void
    {
        $ref = DispatchListReader::templateRef('n8ndispatch.hsm.send', serialize(['hsmTemplateId' => 7, 'hsmId' => 'old']));

        $this->assertSame(['hsm', 7], $ref);
    }

    public function testAStepWithNoTemplateHasNoReference(): void
    {
        $this->assertNull(DispatchListReader::templateRef('n8ndispatch.sms.send', serialize(['body' => 'inline'])));
        $this->assertNull(DispatchListReader::templateRef('n8ndispatch.hsm.send', serialize(['hsmTemplateId' => 0])));
    }

    public function testBadPropertiesOrAnUnknownTypeHaveNoReference(): void
    {
        $this->assertNull(DispatchListReader::templateRef('n8ndispatch.email.send', 'not serialized'));
        $this->assertNull(DispatchListReader::templateRef('campaign.sendemail', serialize(['email' => 1])));
    }

    public function testPropertiesCannotBuildObjects(): void
    {
        $this->assertNull(DispatchListReader::templateRef('n8ndispatch.email.send', serialize(new \ArrayObject(['email' => 1]))));
    }

    public function testTheContactIsNamedByFirstAndLastName(): void
    {
        $this->assertSame('Ana Souza', DispatchListReader::contactName('Ana', 'Souza', 'ana@x.com', 9));
        $this->assertSame('Ana', DispatchListReader::contactName('Ana', null, 'ana@x.com', 9));
    }

    public function testAContactWithNoNameFallsBackToEmailThenId(): void
    {
        $this->assertSame('ana@x.com', DispatchListReader::contactName('', ' ', 'ana@x.com', 9));
        $this->assertSame('#9', DispatchListReader::contactName(null, null, null, 9));
    }

    public function testTheDispatchDateIsReadAsUtc(): void
    {
        $date = DispatchListReader::dispatchedAt('2026-10-02 14:30:00');

        $this->assertSame('2026-10-02 14:30:00', $date?->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $date?->getTimezone()->getName());
    }

    public function testNoOrBadDateGivesNull(): void
    {
        $this->assertNull(DispatchListReader::dispatchedAt(null));
        $this->assertNull(DispatchListReader::dispatchedAt(' '));
        $this->assertNull(DispatchListReader::dispatchedAt('not a date'));
    }

    public function testEachChannelLinksToItsOwnTemplateScreen(): void
    {
        $this->assertSame(
            ['name' => 'mautic_email_action', 'params' => ['objectAction' => 'view', 'objectId' => 5]],
            DispatchListReader::templateRoute('email', 5)
        );
        $this->assertSame(
            ['name' => 'mautic_n8ndispatch.smstemplate_action', 'params' => ['objectAction' => 'edit', 'objectId' => 6]],
            DispatchListReader::templateRoute('sms', 6)
        );
        $this->assertSame(
            ['name' => 'mautic_n8ndispatch.hsmtemplate_action', 'params' => ['objectAction' => 'edit', 'objectId' => 7]],
            DispatchListReader::templateRoute('hsm', 7)
        );
    }

    public function testAnUnknownChannelHasNoLink(): void
    {
        $this->assertNull(DispatchListReader::templateRoute('push', 1));
    }

    public function testTheMetadataIsReadBackAsAnArray(): void
    {
        $this->assertSame(['logSendEmailId' => 9], DispatchListReader::metadataOf(serialize(['logSendEmailId' => 9])));
    }

    public function testMissingOrBadMetadataIsEmpty(): void
    {
        $this->assertSame([], DispatchListReader::metadataOf(null));
        $this->assertSame([], DispatchListReader::metadataOf(''));
        $this->assertSame([], DispatchListReader::metadataOf('not serialized'));
        $this->assertSame([], DispatchListReader::metadataOf(serialize(new \ArrayObject([1]))));
    }

    public function testTheStepModeIsReadFromItsStatusProperty(): void
    {
        $this->assertSame('production', DispatchListReader::stepStatus(serialize(['status' => 'production'])));
        $this->assertSame('paused', DispatchListReader::stepStatus(serialize(['status' => 'paused'])));
        $this->assertSame('test', DispatchListReader::stepStatus(serialize(['status' => 'test'])));
    }

    public function testAStepWithNoOrUnknownStatusCountsAsTest(): void
    {
        $this->assertSame('test', DispatchListReader::stepStatus(serialize(['email' => 1])));
        $this->assertSame('test', DispatchListReader::stepStatus(serialize(['status' => 'weird'])));
        $this->assertSame('test', DispatchListReader::stepStatus('not serialized'));
    }
}
