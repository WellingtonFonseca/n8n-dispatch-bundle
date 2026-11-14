<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\FailedLeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use MauticPlugin\N8nDispatchBundle\Service\Alert\ResendFailedAlert;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendResult;
use MauticPlugin\N8nDispatchBundle\Service\Provider\ProviderResender;
use MauticPlugin\N8nDispatchBundle\Service\ResendVariables;
use MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener\EnUsTranslator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('area:dispatch-hsm')]
#[Group('area:dispatch-history')]
#[Group('risk:CRITICO')]
class ProviderResenderTest extends TestCase
{
    private const REQUEST = [
        'contact_id'         => 7,
        'phone'              => '+5511999990001',
        'language'           => 'pt_BR',
        'template'           => 'welcome_v1',
        'type'               => 'text',
        'variables'          => ['1' => 'Ana', '2' => 'Curso'],
        'whatsapp_number_id' => 3,
    ];

    private ?ResendFailedAlert $alert = null;

    private ?ResendVariables $form = null;

    private ?HsmSendRequest $sent = null;

    /**
     * @param array<string, mixed> $card
     *
     * @return array{ProviderResender, LeadEventLog, MockObject, MockObject}
     */
    private function resender(?HsmSendResult $answer, array $card = [], bool $available = true, int $contactable = DoNotContact::IS_CONTACTABLE): array
    {
        $card += ['mode' => 'dialoghsm', 'status' => 'production', 'request' => self::REQUEST, 'outcome' => 'failed'];

        $event = new Event();
        (new \ReflectionProperty($event, 'id'))->setValue($event, 66);
        $campaign = new Campaign();
        (new \ReflectionProperty($campaign, 'id'))->setValue($campaign, 11);
        $event->setCampaign($campaign);

        $log = new LeadEventLog();
        $log->setEvent($event);
        $log->setLead(new Lead());
        $log->setMetadata(['failed' => 1, 'reason' => 'old', 'errors' => ['x'], 'keep' => 'me', 'n8ndispatch' => $card]);
        $log->setFailedLog(new FailedLeadEventLog());
        $log->setIsScheduled(true);
        $log->setDateTriggered(new \DateTime('2020-01-01'));

        $gateway = $this->createMock(HsmProviderGateway::class);
        $gateway->method('isAvailable')->willReturn($available);

        if (null === $answer) {
            $gateway->expects($this->never())->method('send');
        } else {
            $gateway->method('send')->willReturnCallback(function (HsmSendRequest $request) use ($answer): HsmSendResult {
                $this->sent = $request;

                return $answer;
            });
        }

        $dnc = $this->createMock(DncModel::class);
        $dnc->method('isContactable')->willReturn($contactable);
        $em = $this->createMock(EntityManagerInterface::class);

        $resender = new ProviderResender($em, $gateway, $dnc, new DispatchFailureReasons(new EnUsTranslator()), new EnUsTranslator(), $this->form, $this->alert);

        return [$resender, $log, $gateway, $em];
    }

    public function testARefusedMessageIsHandedAgainWithItsFirstRequestAndTheCampaignIds(): void
    {
        [$resender, $log, , $em] = $this->resender(HsmSendResult::accepted('wamid.N', 200, null));
        $em->expects($this->once())->method('persist')->with($log);
        $em->expects($this->once())->method('flush');
        $em->expects($this->once())->method('remove')->with($this->isInstanceOf(FailedLeadEventLog::class));

        $result = $resender->resend($log, null, 'NOTE', 'a@b.com');

        $this->assertTrue($result['ok']);
        $this->assertSame('Resent.', $result['message']);
        $metadata = $log->getMetadata();
        $this->assertSame('sent', $metadata['n8ndispatch']['outcome']);
        $this->assertSame('wamid.N', $metadata['n8ndispatch']['wamid']);
        $this->assertSame(['1' => 'Ana', '2' => 'Curso'], $metadata['n8ndispatch']['request']['variables']);
        $this->assertSame('NOTE', $metadata['n8ndispatch_resend']);
        $this->assertSame('me', $metadata['keep']);
        $this->assertArrayNotHasKey('failed', $metadata);
        $this->assertArrayNotHasKey('reason', $metadata);
        $this->assertArrayNotHasKey('errors', $metadata);
        $this->assertNull($log->getFailedLog());
        $this->assertFalse($log->getIsScheduled());
        $this->assertGreaterThan(new \DateTime('2021-01-01'), $log->getDateTriggered());
    }

    public function testTheRequestSentCarriesTheIdsOfTheCampaignAndTheStep(): void
    {
        [$resender, $log] = $this->resender(HsmSendResult::accepted(null, 200, null));

        $resender->resend($log, null, 'N', null);

        $this->assertSame(11, $this->sent->campaignId);
        $this->assertSame(66, $this->sent->campaignEventId);
        $this->assertSame(3, $this->sent->numberId);
        $this->assertSame('+5511999990001', $this->sent->phone);
    }

    public function testAMessageTheProviderRefusesAgainIsRecordedFailedKeepsTheCoreFailureAndRaisesTheAlert(): void
    {
        $this->alert = $this->createMock(ResendFailedAlert::class);
        [$resender, $log, , $em] = $this->resender(HsmSendResult::refused('Invalid phone', 400, ['e' => 1]));
        $em->expects($this->never())->method('remove');
        $this->alert->expects($this->once())->method('raise')->with($log, 'hsm', $this->stringContains('Invalid phone'), 'a@b.com');

        $result = $resender->resend($log, null, 'NOTE', 'a@b.com');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid phone', $result['message']);
        $metadata = $log->getMetadata();
        $this->assertSame('failed', $metadata['n8ndispatch']['outcome']);
        $this->assertSame(1, $metadata['failed']);
        $this->assertStringContainsString('Invalid phone', $metadata['reason']);
        $this->assertSame('NOTE', $metadata['n8ndispatch_resend']);
        $this->assertNotNull($log->getFailedLog());
    }

    public function testAnAcceptedResendRaisesNoAlert(): void
    {
        $this->alert = $this->createMock(ResendFailedAlert::class);
        $this->alert->expects($this->never())->method('raise');
        [$resender, $log] = $this->resender(HsmSendResult::accepted(null, 200, null));

        $resender->resend($log, null, 'N', 'a@b.com');
    }

    public function testTheValuesOfTheFormReplaceTheVariablesOfTheRequest(): void
    {
        $this->form = $this->createMock(ResendVariables::class);
        $this->form->method('emptyNames')->willReturn([]);
        [$resender, $log] = $this->resender(HsmSendResult::accepted(null, 200, null));

        $resender->resend($log, ['2' => 'Outro'], 'N', null);

        $this->assertSame(['1' => 'Ana', '2' => 'Outro'], $log->getMetadata()['n8ndispatch']['request']['variables']);
    }

    public function testAFormWithAnEmptyVariableOfTheTemplateSendsAndRecordsNothing(): void
    {
        $this->form = $this->createMock(ResendVariables::class);
        $this->form->method('emptyNames')->willReturn(['3', '4']);
        [$resender, $log, , $em] = $this->resender(null);
        $em->expects($this->never())->method('flush');

        $result = $resender->resend($log, ['1' => 'x'], 'N', null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3, 4', $result['message']);
        $this->assertSame('failed', $log->getMetadata()['n8ndispatch']['outcome']);
    }

    public function testAnEmptyValueOfTheFirstRequestIsRefusedBeforeTheProviderIsCalled(): void
    {
        [$resender, $log] = $this->resender(null, ['request' => ['variables' => ['1' => 'Ana', '2' => '  ']] + self::REQUEST]);

        $result = $resender->resend($log, null, 'N', null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('{{2}}', $result['message']);
        $this->assertSame(1, $log->getMetadata()['failed']);
    }

    public function testAMessageTheProviderAcceptedIsNotResentHere(): void
    {
        [$resender, $log, , $em] = $this->resender(null, ['outcome' => 'sent']);
        $em->expects($this->never())->method('flush');

        $result = $resender->resend($log, null, 'N', null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('refused', $result['message']);
    }

    public function testNothingIsSentWhenTheProviderIsNotAvailable(): void
    {
        [$resender, $log] = $this->resender(null, [], false);

        $result = $resender->resend($log, null, 'N', null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not available', $result['message']);
    }

    public function testALogWithoutAUsableRequestHasNoBodyToResend(): void
    {
        [$resender, $log] = $this->resender(null, ['request' => ['phone' => '+55']]);

        $result = $resender->resend($log, null, 'N', null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no saved body', $result['message']);
    }

    public function testAContactWhoOptedOutSinceIsNotSentToAndNothingIsRecorded(): void
    {
        [$resender, $log, , $em] = $this->resender(null, [], true, DoNotContact::UNSUBSCRIBED);
        $em->expects($this->never())->method('flush');

        $result = $resender->resend($log, null, 'N', null);

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $log->getMetadata()['n8ndispatch']['outcome']);
        $this->assertStringContainsString('sms', $result['message']);
    }
}
