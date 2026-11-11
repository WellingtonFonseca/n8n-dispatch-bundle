<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\EventListener\HsmCampaignTriggerSubscriber;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendResult;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The "Send via n8n (HSM)" step of a template that is sent through the DialogHSM: the plugin hands the message over and
 * its answer is the end of our part, like the Email sent through Mautic's own mailer. No webhook, no tracking rows, no History.
 */
class HsmCampaignTriggerSubscriberProviderTest extends TestCase
{
    private IntegrationHelper&MockObject $integrationHelper;

    private HttpClientInterface&MockObject $httpClient;

    private VariableResolver&MockObject $variableResolver;

    private DncModel&MockObject $dncModel;

    private HsmTemplateModel&MockObject $hsmTemplateModel;

    private StatusTracker&MockObject $statusTracker;

    private HsmProviderGateway&MockObject $gateway;

    private HsmTemplate $template;

    private HsmCampaignTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->variableResolver  = $this->createMock(VariableResolver::class);
        $this->dncModel          = $this->createMock(DncModel::class);
        $this->dncModel->method('isContactable')->willReturn(DoNotContact::IS_CONTACTABLE);
        $this->hsmTemplateModel = $this->createMock(HsmTemplateModel::class);
        $this->statusTracker    = $this->createMock(StatusTracker::class);
        $this->gateway          = $this->createMock(HsmProviderGateway::class);

        $this->variableResolver->method('resolveAll')->willReturn(['2' => 'Curso X', '1' => 'Ana']);

        $this->subscriber = new HsmCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->createMock(LoggerInterface::class),
            $this->variableResolver,
            $this->dncModel,
            $this->hsmTemplateModel,
            new DispatchFailureReasons(new EnUsTranslator()),
            $this->statusTracker,
            $this->gateway,
        );

        // The template the step points at (id 7); a test that needs another shape changes this object.
        $this->template = (new HsmTemplate())
            ->setName('t')
            ->setHsmTemplate('welcome_v1')
            ->setType(HsmTemplate::TYPE_TEXT)
            ->setProvider(HsmTemplate::PROVIDER_DIALOGHSM)
            ->setWhatsappNumberId(3)
            ->setLanguage('pt_BR')
            ->setVariablesJson('{"1":{"source":"field","field":"firstname"},"2":{"source":"static","value":"Curso X"}}');
        $this->hsmTemplateModel->method('getEntity')->with(7)->willReturn($this->template);
    }

    private function pendingEvent(string $status, ?string $phone = '11999990001'): PendingEvent
    {
        $campaign = new Campaign();
        (new \ReflectionProperty(Campaign::class, 'id'))->setValue($campaign, 11);

        $event = new Event();
        $event->setType('n8ndispatch.hsm.send');
        $event->setCampaign($campaign);
        $event->setProperties(['hsmTemplateId' => '7', 'status' => $status]);
        (new \ReflectionProperty(Event::class, 'id'))->setValue($event, 22);

        $contact = new Lead();
        (new \ReflectionProperty(Lead::class, 'id'))->setValue($contact, 5);
        if (null !== $phone) {
            $contact->setPhone($phone);
        }

        $log = new LeadEventLog();
        $log->setLead($contact);

        return new PendingEvent(new ActionAccessor([]), $event, new ArrayCollection([$log]));
    }

    public function testAProductionStepHandsTheMessageToTheProviderAndPasses(): void
    {
        $this->gateway->expects($this->once())->method('send')
            ->with($this->callback(function (HsmSendRequest $request): bool {
                return 5 === $request->contactId
                    && '11999990001' === $request->phone
                    && 'pt_BR' === $request->language
                    && 'welcome_v1' === $request->template
                    && 'text' === $request->type
                    && [1 => 'Ana', 2 => 'Curso X'] === $request->variables
                    && 3 === $request->numberId
                    && 11 === $request->campaignId
                    && 22 === $request->campaignEventId;
            }))
            ->willReturn(HsmSendResult::accepted('wamid.X', 200, ['messages' => [['id' => 'wamid.X']]]));
        // The webhook of n8n is not read, nothing is posted and nothing is tracked.
        $this->integrationHelper->expects($this->never())->method('getIntegrationObject');
        $this->httpClient->expects($this->never())->method('request');
        $this->statusTracker->expects($this->never())->method('register');
        $this->statusTracker->expects($this->never())->method('recordSimulated');

        $pending = $this->pendingEvent('production');
        $this->subscriber->onHsmSend($pending);

        $this->assertCount(0, $pending->getFailures());
        $this->assertCount(1, $pending->getSuccessful());

        /** @var LeadEventLog $log */
        $log  = $pending->getSuccessful()->first();
        $card = $log->getMetadata()['n8ndispatch'];
        $this->assertSame('dialoghsm', $card['mode']);
        $this->assertSame('production', $card['status']);
        $this->assertSame('sent', $card['outcome']);
        $this->assertSame('wamid.X', $card['wamid']);
        $this->assertSame('welcome_v1', $card['request']['template']);
        $this->assertSame(['1' => 'Ana', '2' => 'Curso X'], $card['request']['variables']);
        $this->assertTrue($card['result']['accepted']);
        $this->assertSame(200, $card['result']['http_status']);
    }

    public function testAMessageTheProviderRefusesFailsTheStepWithItsReason(): void
    {
        $this->gateway->method('send')->willReturn(HsmSendResult::refused('[API 360dialog] template not found', 404, ['error' => 'x']));

        $pending = $this->pendingEvent('production');
        $this->subscriber->onHsmSend($pending);

        $this->assertCount(0, $pending->getSuccessful());
        $this->assertCount(1, $pending->getFailures());

        /** @var LeadEventLog $log */
        $log = $pending->getFailures()->first();
        $this->assertSame('N8nDispatch: [API 360dialog] template not found', $log->getFailedLog()->getReason());
        $card = $log->getMetadata()['n8ndispatch'];
        $this->assertSame('dialoghsm', $card['mode']);
        $this->assertSame('failed', $card['outcome']);
        $this->assertFalse($card['result']['accepted']);
        $this->assertSame(404, $card['result']['http_status']);
    }

    public function testAVariableWithoutAValueFailsBeforeAnythingIsSent(): void
    {
        $resolver = $this->createMock(VariableResolver::class);
        $resolver->method('resolveAll')->willReturn(['1' => 'Ana', '2' => '   ']);
        $subscriber = new HsmCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->createMock(LoggerInterface::class),
            $resolver,
            $this->dncModel,
            $this->hsmTemplateModel,
            new DispatchFailureReasons(new EnUsTranslator()),
            $this->statusTracker,
            $this->gateway,
        );
        // The Meta refuses a parameter with no text: the message would only make a failed call and a failed log on the other plugin.
        $this->gateway->expects($this->never())->method('send');

        $pending = $this->pendingEvent('production');
        $subscriber->onHsmSend($pending);

        $this->assertCount(1, $pending->getFailures());
        /** @var LeadEventLog $log */
        $log = $pending->getFailures()->first();
        $this->assertSame('N8nDispatch: the variable {{2}} has no value for this contact.', $log->getFailedLog()->getReason());
        $this->assertSame('failed', $log->getMetadata()['n8ndispatch']['outcome']);
        $this->assertSame('dialoghsm', $log->getMetadata()['n8ndispatch']['mode']);
    }

    public function testAVariableWithTheValueZeroIsAValue(): void
    {
        $resolver = $this->createMock(VariableResolver::class);
        $resolver->method('resolveAll')->willReturn(['1' => '0']);
        $subscriber = new HsmCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->createMock(LoggerInterface::class),
            $resolver,
            $this->dncModel,
            $this->hsmTemplateModel,
            new DispatchFailureReasons(new EnUsTranslator()),
            $this->statusTracker,
            $this->gateway,
        );
        $this->gateway->expects($this->once())->method('send')->willReturn(HsmSendResult::accepted('w', 200, null));

        $pending = $this->pendingEvent('production');
        $subscriber->onHsmSend($pending);

        $this->assertCount(1, $pending->getSuccessful());
    }

    public function testAContactWhoOptedOutIsPassedWithTheReasonAndNothingIsSent(): void
    {
        $dnc = $this->createMock(DncModel::class);
        $dnc->method('isContactable')->willReturn(DoNotContact::UNSUBSCRIBED);
        $subscriber = new HsmCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->createMock(LoggerInterface::class),
            $this->variableResolver,
            $dnc,
            $this->hsmTemplateModel,
            new DispatchFailureReasons(new EnUsTranslator()),
            $this->statusTracker,
            $this->gateway,
        );
        $this->gateway->expects($this->never())->method('send');

        $pending = $this->pendingEvent('production');
        $subscriber->onHsmSend($pending);

        $this->assertCount(0, $pending->getFailures());
        $this->assertCount(1, $pending->getSuccessful());
    }

    public function testAContactWithoutAPhoneFailsAndNothingIsSent(): void
    {
        $this->gateway->expects($this->never())->method('send');

        $pending = $this->pendingEvent('production', null);
        $this->subscriber->onHsmSend($pending);

        $this->assertCount(1, $pending->getFailures());
        $this->assertSame('N8nDispatch: contact has no phone number.', $pending->getFailures()->first()->getFailedLog()->getReason());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stepsThatSendNothing(): array
    {
        return ['test' => ['test'], 'paused' => ['paused']];
    }

    /**
     * @dataProvider stepsThatSendNothing
     */
    public function testATestOrPausedStepShowsWhatWouldGoToTheProviderAndSendsNothing(string $status): void
    {
        $this->gateway->expects($this->never())->method('send');
        $this->statusTracker->expects($this->never())->method('recordSimulated');

        $pending = $this->pendingEvent($status);
        $this->subscriber->onHsmSend($pending);

        $this->assertCount(0, $pending->getFailures());
        /** @var LeadEventLog $log */
        $log  = $pending->getSuccessful()->first();
        $card = $log->getMetadata()['n8ndispatch'];
        $this->assertSame('dialoghsm', $card['mode']);
        $this->assertSame($status, $card['status']);
        $this->assertSame('welcome_v1', $card['request']['template']);
        $this->assertSame(3, $card['request']['whatsapp_number_id']);
        $this->assertArrayNotHasKey('outcome', $card);
        $this->assertArrayNotHasKey('result', $card);
    }

    public function testACarouselTemplateHandsOverItsCards(): void
    {
        $this->template->setType(HsmTemplate::TYPE_CAROUSEL)->setCards(['https://x.test/a', 'https://x.test/b']);
        $this->gateway->expects($this->once())->method('send')
            ->with($this->callback(fn (HsmSendRequest $r): bool => 'carousel' === $r->type && [
                ['card_index' => 0, 'header_image_link' => 'https://x.test/a'],
                ['card_index' => 1, 'header_image_link' => 'https://x.test/b'],
            ] === $r->cards))
            ->willReturn(HsmSendResult::accepted('w', 200, null));

        $this->subscriber->onHsmSend($this->pendingEvent('production'));
    }
}
