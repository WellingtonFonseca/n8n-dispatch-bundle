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
}
