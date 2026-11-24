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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The "Send via n8n (HSM)" step of a template that is sent through the DialogHSM: the plugin hands the message over and
 * its answer is the end of our part, like the Email sent through Mautic's own mailer. No webhook, no tracking rows, no History.
 */
#[Group('area:dispatch-hsm')]
#[Group('risk:CRITICO')]
#[Group('risk:FLUXO')]
class HsmCampaignTriggerSubscriberButtonsTest extends TestCase
{
    private HsmProviderGateway&MockObject $gateway;

    private HsmTemplate $template;

    /** @var array<int|string, string> */
    private array $resolved = ['1' => 'Ana'];

    private function subscriber(): HsmCampaignTriggerSubscriber
    {
        $dnc = $this->createMock(DncModel::class);
        $dnc->method('isContactable')->willReturn(DoNotContact::IS_CONTACTABLE);
        $resolver = $this->createMock(VariableResolver::class);
        $resolver->method('resolveAll')->willReturn($this->resolved);
        $model = $this->createMock(HsmTemplateModel::class);
        $model->method('getEntity')->with(7)->willReturn($this->template);
        $this->gateway = $this->createMock(HsmProviderGateway::class);

        return new HsmCampaignTriggerSubscriber(
            $this->createMock(IntegrationHelper::class),
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $resolver,
            $dnc,
            $model,
            new DispatchFailureReasons(new EnUsTranslator()),
            $this->createMock(StatusTracker::class),
            $this->gateway,
        );
    }

    protected function setUp(): void
    {
        $this->template = (new HsmTemplate())
            ->setName('t')
            ->setHsmTemplate('welcome_v1')
            ->setType(HsmTemplate::TYPE_TEXT)
            ->setProvider(HsmTemplate::PROVIDER_DIALOGHSM)
            ->setWhatsappNumberId(3)
            ->setLanguage('pt_BR')
            ->setVariablesJson('{"1":{"source":"static","value":"Ana"}}');
    }

    private function pending(): PendingEvent
    {
        $campaign = new Campaign();
        (new \ReflectionProperty(Campaign::class, 'id'))->setValue($campaign, 11);
        $event = new Event();
        $event->setType('n8ndispatch.hsm.send');
        $event->setCampaign($campaign);
        $event->setProperties(['hsmTemplateId' => '7', 'status' => 'production']);
        (new \ReflectionProperty(Event::class, 'id'))->setValue($event, 22);
        $contact = new Lead();
        (new \ReflectionProperty(Lead::class, 'id'))->setValue($contact, 5);
        $contact->setPhone('11999990001');
        $log = new LeadEventLog();
        $log->setLead($contact);

        return new PendingEvent(new ActionAccessor([]), $event, new ArrayCollection([$log]));
    }

    public function testButtonRowsLeaveTheBodyVariablesAndGoAsButtonVariables(): void
    {
        $this->resolved = ['1' => 'Ana', 'button_1_url' => 'abc', 'button_0_copy_code' => 'CUPOM10'];
        $subscriber     = $this->subscriber();
        $this->gateway->expects($this->once())->method('send')
            ->with($this->callback(fn (HsmSendRequest $r): bool => [1 => 'Ana'] === $r->variables
                && [0 => ['type' => 'copy_code', 'value' => 'CUPOM10'], 1 => ['type' => 'url', 'value' => 'abc']] === $r->buttonVariables))
            ->willReturn(HsmSendResult::accepted('w', 200, null));

        $subscriber->onHsmSend($this->pending());
    }

    public function testAButtonWithNoValueFailsTheStepAndSendsNothing(): void
    {
        $this->resolved = ['1' => 'Ana', 'button_1_url' => ' '];
        $subscriber     = $this->subscriber();
        $this->gateway->expects($this->never())->method('send');

        $pending = $this->pending();
        $subscriber->onHsmSend($pending);

        $this->assertCount(1, $pending->getFailures());
    }

    public function testTheLanguageThe360dialogGaveTheTemplateIsTheOneSent(): void
    {
        $this->template->setTemplateLanguage('pt_PT');
        $subscriber = $this->subscriber();
        $this->gateway->expects($this->once())->method('send')
            ->with($this->callback(fn (HsmSendRequest $r): bool => 'pt_PT' === $r->language))
            ->willReturn(HsmSendResult::accepted('w', 200, null));

        $subscriber->onHsmSend($this->pending());
    }

    public function testATemplateSavedBeforeTheTemplateLanguageKeepsTheOldLanguage(): void
    {
        $subscriber = $this->subscriber();
        $this->gateway->expects($this->once())->method('send')
            ->with($this->callback(fn (HsmSendRequest $r): bool => 'pt_BR' === $r->language))
            ->willReturn(HsmSendResult::accepted('w', 200, null));

        $subscriber->onHsmSend($this->pending());
    }
}
