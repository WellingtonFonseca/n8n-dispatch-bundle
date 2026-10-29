<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\SmsBundle\Sms\TransportInterface;
use MauticPlugin\N8nDispatchBundle\Service\N8nSmsTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class N8nSmsTransportTest extends TestCase
{
    public function testItIsAnSmsTransportOfCore(): void
    {
        $this->assertInstanceOf(TransportInterface::class, new N8nSmsTransport($this->createMock(TranslatorInterface::class)));
    }

    public function testItSendsNothingAndAnswersWithTheTranslatedReason(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with('mautic.n8ndispatch.sms_transport.not_supported')->willReturn('use the n8n action');

        // core takes anything but `true` as a failure and keeps the string as the reason on the Stat
        $this->assertSame('use the n8n action', (new N8nSmsTransport($translator))->sendSms(new Lead(), 'text'));
    }

    public function testTheTransportIsTaggedForCoreOnTheIntegrationOfThePlugin(): void
    {
        $config = require __DIR__.'/../../../Config/config.php';
        $service = $config['services']['other']['mautic.n8ndispatch.sms_transport'];

        $this->assertSame(N8nSmsTransport::class, $service['class']);
        $this->assertSame('mautic.sms_transport', $service['tag']);
        $this->assertSame(['integrationAlias' => 'N8nDispatch'], $service['tagArguments']);
    }
}
