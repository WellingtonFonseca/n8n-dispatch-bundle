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
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\EventListener\HsmCampaignTriggerSubscriber;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HsmCampaignTriggerSubscriberTest extends TestCase
{
    private IntegrationHelper $integrationHelper;

    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private VariableResolver $variableResolver;

    private DncModel $dncModel;

    private HsmCampaignTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->variableResolver  = $this->createMock(VariableResolver::class);
        $this->dncModel          = $this->createMock(DncModel::class);
        // Default every test to "contactable" so the existing dispatch/
        // pass-path tests don't each have to configure this themselves —
        // tests specifically about the DNC gate override it.
        $this->dncModel->method('isContactable')->willReturn(DoNotContact::IS_CONTACTABLE);

        $this->subscriber = new HsmCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->variableResolver,
            $this->dncModel,
        );

        $this->variableResolver->method('resolveAll')->willReturn(['nome' => 'Wellington']);
    }

    private function mockIntegration(bool $isPublished, array $keys): void
    {
        $settings = $this->createMock(IntegrationSettings::class);
        $settings->method('isPublished')->willReturn($isPublished);

        $integration = $this->createMock(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $integration->method('getKeys')->willReturn($keys);

        $this->integrationHelper->method('getIntegrationObject')
            ->with(N8nDispatchIntegration::NAME)
            ->willReturn($integration);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function buildPendingEvent(array $properties, ?string $phone = '+5511999999999'): PendingEvent
    {
        $campaign = new Campaign();

        $event = new Event();
        $event->setType('n8ndispatch.hsm.send');
        $event->setCampaign($campaign);
        $event->setProperties($properties);

        $contact = new Lead();
        $contact->setEmail('contact@example.test');
        if (null !== $phone) {
            $contact->setPhone($phone);
        }

        $log = new LeadEventLog();
        $log->setLead($contact);

        return new PendingEvent(new ActionAccessor([]), $event, new ArrayCollection([$log]));
    }

    public function testPausedStatusRecordsPayloadOnTimelineWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'paused']);

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $this->assertCount(0, $pendingEvent->getFailures());
        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);

        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame('paused', $passedLog->getMetadata()['n8ndispatch']['status']);
    }

    public function testTestStatusRecordsTheFullPayloadWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'test']);

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $payload   = $passedLog->getMetadata()['n8ndispatch'];

        $this->assertSame(
            [
                'contact_id'                     => 0,
                'contact_email'                  => 'contact@example.test',
                'contact_phone'                  => '+5511999999999',
                'contact_name'                   => '',
                'contact_inst_id_lyceum'         => '',
                'contact_inst_id_company'        => '',
                'contact_inst_alias'             => '',
                'status'                         => 'test',
                'hsm_router'                     => 'r1',
                'hsm_id'                         => 'h1',
                'variables'                      => ['nome' => 'Wellington'],
            ],
            $payload
        );
    }

    public function testMissingStatusDefaultsToTestAndRecordsTimelineWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1']);

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame('test', $passedLog->getMetadata()['n8ndispatch']['status']);
    }

    public function testProductionStatusFailAllWhenIntegrationNotConfigured(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->integrationHelper->method('getIntegrationObject')->willReturn(null);
        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getFailures());
    }

    public function testProductionStatusFailAllWhenWebhookUrlIsMissing(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => '']);
        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getFailures());
    }

    public function testProductionStatusFailsWithoutDispatchingWhenContactIsOnTheSmsDncList(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $this->dncModel = $this->createMock(DncModel::class);
        $this->dncModel->method('isContactable')->with($this->anything(), 'sms')->willReturn(DoNotContact::UNSUBSCRIBED);
        $this->subscriber = new HsmCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->variableResolver,
            $this->dncModel,
        );

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame(
            'N8nDispatch: contact is on the Do Not Contact list for sms.',
            $failedLog->getFailedLog()->getReason()
        );
    }

    public function testProductionStatusFailsAContactWithNoPhoneNumberWithoutCallingTheWebhook(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production'], phone: null);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);
        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onHsmSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame('N8nDispatch: contact has no phone number.', $failedLog->getFailedLog()->getReason());
    }

    public function testProductionStatusDispatchesTheRouterHsmIdAndVariables(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, [
            'webhook_url'   => 'https://n8n.example.test/webhook/dispatch',
            'webhook_token' => 'secret-token',
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        // A non-empty body — see testProductionStatusTreatsAnEmpty2xxResponseAsAFailure
        // for what happens when n8n's workflow returns nothing at all.
        $response->method('getContent')->with(false)->willReturn('{"ok":true}');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://n8n.example.test/webhook/dispatch',
                $this->callback(function (array $options): bool {
                    $this->assertSame('hsm.send', $options['headers']['X-N8n-Dispatch-Action']);
                    $this->assertSame('secret-token', $options['headers']['X-N8n-Dispatch-Token']);
                    $this->assertSame('production', $options['json']['status']);
                    $this->assertSame('r1', $options['json']['hsm_router']);
                    $this->assertSame('h1', $options['json']['hsm_id']);
                    $this->assertSame('+5511999999999', $options['json']['contact_phone']);
                    $this->assertSame(['nome' => 'Wellington'], $options['json']['variables']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->subscriber->onHsmSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
        $this->assertCount(0, $pendingEvent->getFailures());
    }

    /**
     * Confirmed live against the user's real n8n instance: a 200 status
     * with a completely empty body, meaning the webhook call landed but
     * nothing indicates a WhatsApp send actually happened (this channel's
     * n8n workflow has no real hsm.send handling wired up yet, unlike
     * email.send/sms.send). Until it does, treat that specific shape as a
     * failure too, not just statusCode >= 300, on request.
     */
    public function testProductionStatusTreatsAnEmpty2xxResponseAsAFailure(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('');
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onHsmSend($pendingEvent);

        $this->assertCount(0, $pendingEvent->getSuccessful());
        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame('N8nDispatch: n8n returned an empty response for hsm.send.', $failedLog->getFailedLog()->getReason());
        // Still 200 — the transport call itself succeeded, only the body
        // was empty, so the badge/outcome data should reflect that
        // truthfully rather than pretending the call itself failed.
        $this->assertSame(200, $failedLog->getMetadata()['n8ndispatch']['httpStatusCode']);
    }

    public function testProductionStatusFailureReasonUsesTheErrorMessageFromTheResponseBody(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getContent')->with(false)->willReturn(
            '{"statusCode":404,"statusMessage":"Not Found","error":"code 404, Entity not found - hsm template"}'
        );
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onHsmSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame(
            'N8nDispatch: code 404, Entity not found - hsm template',
            $failedLog->getFailedLog()->getReason()
        );
        $this->assertSame(404, $failedLog->getMetadata()['n8ndispatch']['httpStatusCode']);
    }

    public function testProductionStatusOnTransportException(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $this->subscriber->onHsmSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame('N8nDispatch: Connection refused', $failedLog->getFailedLog()->getReason());
    }

    public function testSuccessAttachesLogSendHsmIdToTheLogMetadataFromFlatBody(): void
    {
        $pendingEvent = $this->buildPendingEvent(['router' => 'r1', 'hsmId' => 'h1', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"logSendHsmId":4242}');
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onHsmSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $metadata  = $passedLog->getMetadata();
        $this->assertSame(4242, $metadata['logSendHsmId']);
        $this->assertSame(['logSendHsmId' => 4242], $metadata['n8ndispatch']['response']);
    }
}
