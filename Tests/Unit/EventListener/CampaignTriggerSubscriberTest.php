<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\EventListener\CampaignTriggerSubscriber;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CampaignTriggerSubscriberTest extends TestCase
{
    private IntegrationHelper $integrationHelper;

    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private EmailModel $emailModel;

    private VariableResolver $variableResolver;

    private CampaignTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->emailModel        = $this->createMock(EmailModel::class);
        $this->variableResolver  = $this->createMock(VariableResolver::class);

        $this->subscriber = new CampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->emailModel,
            $this->variableResolver,
        );

        $this->variableResolver->method('resolveAll')->willReturn(['foo' => 'bar']);
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
    private function buildPendingEvent(array $properties): PendingEvent
    {
        $campaign = new Campaign();

        $event = new Event();
        $event->setType('n8ndispatch.email.send');
        $event->setCampaign($campaign);
        $event->setProperties($properties);

        $contact = new Lead();
        $contact->setEmail('contact@example.test');

        $log = new LeadEventLog();
        $log->setLead($contact);

        return new PendingEvent(new ActionAccessor([]), $event, new ArrayCollection([$log]));
    }

    public function testPausedStatusRecordsPayloadOnTimelineWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'paused']);

        $this->httpClient->expects($this->never())->method('request');
        $this->emailModel->expects($this->never())->method('getEntity');

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(0, $pendingEvent->getFailures());
        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);

        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame('paused', $passedLog->getMetadata()['n8ndispatch']['status']);
    }

    public function testProductionStatusDispatchesAndIncludesStatusInPayload(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://n8n.example.test/webhook/dispatch',
                $this->callback(function (array $options): bool {
                    $this->assertSame('production', $options['json']['status']);
                    $this->assertSame(1, $options['json']['mautic_template_id']);
                    $this->assertSame('contact@example.test', $options['json']['contact_email']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
        $this->assertCount(0, $pendingEvent->getFailures());
    }

    public function testTestStatusRecordsPayloadOnTimelineWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'test']);

        $this->httpClient->expects($this->never())->method('request');
        $this->emailModel->expects($this->never())->method('getEntity');

        $this->subscriber->onEmailSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        $this->assertCount(0, $pendingEvent->getFailures());

        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame(
            [
                'mautic_template_id' => 1,
                'contact_id'         => 0,
                'contact_email'      => 'contact@example.test',
                'status'             => 'test',
                'variables'          => ['foo' => 'bar'],
            ],
            $passedLog->getMetadata()['n8ndispatch']
        );
    }

    public function testMissingStatusDefaultsToTestAndRecordsTimelineWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1]);

        $this->httpClient->expects($this->never())->method('request');
        $this->emailModel->expects($this->never())->method('getEntity');

        $this->subscriber->onEmailSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame('test', $passedLog->getMetadata()['n8ndispatch']['status']);
    }

    public function testSuccessAttachesLogSendEmailIdToTheLogMetadataFromFlatBody(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"logSendEmailId":6334025}');
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $metadata  = $passedLog->getMetadata();
        $this->assertSame(6334025, $metadata['logSendEmailId']);
        $this->assertSame('production', $metadata['n8ndispatch']['status']);
        $this->assertSame(['logSendEmailId' => 6334025], $metadata['n8ndispatch']['response']);
    }

    public function testSuccessAttachesLogSendEmailIdToTheLogMetadataFromNestedBody(): void
    {
        // The real shape n8n's "Respond to Webhook" sends today — it
        // forwards the Mirror HTTP node's raw output as-is, which nests
        // the actual payload one level under 'body'.
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn(
            '{"body":{"logSendEmailId":6334029},"statusCode":200,"statusMessage":"OK"}'
        );
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $metadata  = $passedLog->getMetadata();
        $this->assertSame(6334029, $metadata['logSendEmailId']);
        $this->assertSame('production', $metadata['n8ndispatch']['status']);
        // The full raw response is kept as-is, nested 'body' included — the
        // Timeline card dumps it verbatim, it doesn't re-shape it.
        $this->assertSame(
            ['body' => ['logSendEmailId' => 6334029], 'statusCode' => 200, 'statusMessage' => 'OK'],
            $metadata['n8ndispatch']['response']
        );
    }

    public function testSuccessWithoutLogSendEmailIdInBodyStillPasses(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('not valid json');
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertArrayNotHasKey('logSendEmailId', $passedLog->getMetadata());
        // Falls back to the raw string when the response isn't valid JSON,
        // so the Timeline card still has something to show.
        $this->assertSame('not valid json', $passedLog->getMetadata()['n8ndispatch']['response']);
    }

    public function testFailureReasonUsesTheErrorMessageFromTheResponseBody(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getContent')->with(false)->willReturn(
            '{"statusCode":404,"statusMessage":"Not Found","error":"code 404, Entity not found - contact"}'
        );
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame(
            'N8nDispatch: code 404, Entity not found - contact',
            $failedLog->getFailedLog()->getReason()
        );
    }

    public function testFailureReasonFallsBackToHttpCodeWhenResponseBodyHasNoErrorField(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->with(false)->willReturn('not valid json');
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame(
            'N8nDispatch: webhook returned HTTP 500.',
            $failedLog->getFailedLog()->getReason()
        );
    }
}
