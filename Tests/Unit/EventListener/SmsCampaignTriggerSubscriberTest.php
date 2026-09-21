<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Entity\Sms;
use Mautic\SmsBundle\Entity\Stat;
use MauticPlugin\N8nDispatchBundle\EventListener\SmsCampaignTriggerSubscriber;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SmsCampaignTriggerSubscriberTest extends TestCase
{
    private IntegrationHelper $integrationHelper;

    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private VariableResolver $variableResolver;

    private EntityManagerInterface $entityManager;

    private ObjectRepository $smsRepository;

    private SmsCampaignTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->variableResolver  = $this->createMock(VariableResolver::class);
        $this->entityManager     = $this->createMock(EntityManagerInterface::class);
        $this->smsRepository     = $this->createMock(ObjectRepository::class);

        $this->subscriber = new SmsCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->variableResolver,
            $this->entityManager,
        );

        $this->variableResolver->method('resolveAll')->willReturn(['foo' => 'bar']);
        $this->entityManager->method('getRepository')->with(Sms::class)->willReturn($this->smsRepository);
        // Default: an Sms row for this text already exists, so
        // resolveSmsTemplate() never needs to persist one — tests only
        // about Stat creation don't have to account for that extra call.
        // Tests specifically about resolveSmsTemplate() itself override this.
        $this->smsRepository->method('findOneBy')->willReturn(new Sms());
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
        $event->setType('n8ndispatch.sms.send');
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
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'paused']);

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onSmsSend($pendingEvent);

        $this->assertCount(0, $pendingEvent->getFailures());
        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);

        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame('paused', $passedLog->getMetadata()['n8ndispatch']['status']);
    }

    public function testTestStatusResolvesVariablesIntoTheMessageWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}, code {{missing}}', 'status' => 'test']);

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onSmsSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $payload   = $passedLog->getMetadata()['n8ndispatch'];

        $this->assertSame(
            [
                'contact_id'             => 0,
                'contact_email'          => 'contact@example.test',
                'contact_phone'          => '+5511999999999',
                'contact_ies_id_lyceum'  => '',
                'contact_ies_id_company' => '',
                'status'                 => 'test',
                // {{foo}} resolved from the variable map; {{missing}} has no
                // entry in it (variablesJson only ever has keys the form
                // actually scanned out of the text), so it's left as-is
                // rather than blanked out.
                'message'                => 'Hi bar, code {{missing}}',
                'variables'              => ['foo' => 'bar'],
            ],
            $payload
        );
    }

    public function testMissingStatusDefaultsToTestAndRecordsTimelineWithoutDispatching(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}']);

        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onSmsSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $this->assertSame('test', $passedLog->getMetadata()['n8ndispatch']['status']);
    }

    public function testProductionStatusFailAllWhenIntegrationNotConfigured(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $this->integrationHelper->method('getIntegrationObject')->willReturn(null);
        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onSmsSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getFailures());
    }

    public function testProductionStatusFailAllWhenWebhookUrlIsMissing(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => '']);
        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onSmsSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getFailures());
    }

    public function testProductionStatusFailsAContactWithNoPhoneNumberWithoutCallingTheWebhook(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production'], phone: null);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);
        $this->httpClient->expects($this->never())->method('request');

        $this->subscriber->onSmsSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame('N8nDispatch: contact has no phone number.', $failedLog->getFailedLog()->getReason());
    }

    public function testProductionStatusDispatchesTheResolvedMessageAndPhoneNumber(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $this->mockIntegration(true, [
            'webhook_url'   => 'https://n8n.example.test/webhook/dispatch',
            'webhook_token' => 'secret-token',
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://n8n.example.test/webhook/dispatch',
                $this->callback(function (array $options): bool {
                    $this->assertSame('sms.send', $options['headers']['X-N8n-Dispatch-Action']);
                    $this->assertSame('secret-token', $options['headers']['X-N8n-Dispatch-Token']);
                    $this->assertSame('production', $options['json']['status']);
                    $this->assertSame('Hi bar', $options['json']['message']);
                    $this->assertSame('+5511999999999', $options['json']['contact_phone']);
                    $this->assertSame('contact@example.test', $options['json']['contact_email']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(Stat::class));
        $this->entityManager->expects($this->once())->method('flush');

        $this->subscriber->onSmsSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
        $this->assertCount(0, $pendingEvent->getFailures());
    }

    public function testProductionStatusCreatesAFailedStatOnHttpFailure(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getContent')->with(false)->willReturn(
            '{"statusCode":404,"statusMessage":"Not Found","error":"code 404, Entity not found - contact"}'
        );
        $this->httpClient->method('request')->willReturn($response);

        $capturedStat = null;
        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->callback(function (Stat $stat) use (&$capturedStat): bool {
                $capturedStat = $stat;

                return true;
            }));

        $this->subscriber->onSmsSend($pendingEvent);

        $this->assertTrue($capturedStat->isFailed());
        $this->assertInstanceOf(Sms::class, $capturedStat->getSms());

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame(
            'N8nDispatch: code 404, Entity not found - contact',
            $failedLog->getFailedLog()->getReason()
        );
        $this->assertSame(404, $failedLog->getMetadata()['n8ndispatch']['httpStatusCode']);
    }

    public function testProductionStatusCreatesAFailedStatOnTransportException(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $capturedStat = null;
        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->callback(function (Stat $stat) use (&$capturedStat): bool {
                $capturedStat = $stat;

                return true;
            }));

        $this->subscriber->onSmsSend($pendingEvent);

        $this->assertTrue($capturedStat->isFailed());

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $this->assertSame('N8nDispatch: Connection refused', $failedLog->getFailedLog()->getReason());
    }

    public function testSuccessAttachesLogSendSmsIdToTheLogMetadataFromFlatBody(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"logSendSmsId":9001}');
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onSmsSend($pendingEvent);

        $successful = $pendingEvent->getSuccessful();
        $this->assertCount(1, $successful);
        /** @var LeadEventLog $passedLog */
        $passedLog = $successful->first();
        $metadata  = $passedLog->getMetadata();
        $this->assertSame(9001, $metadata['logSendSmsId']);
        $this->assertSame(['logSendSmsId' => 9001], $metadata['n8ndispatch']['response']);
    }

    /**
     * The default setUp() stub already returns an existing Sms for every
     * findOneBy() call (see its own comment) — every other production
     * test above already exercises the "reuse" branch implicitly, and
     * asserts a Stat's getSms() is an Sms instance
     * (testProductionStatusCreatesAFailedStatOnHttpFailure). This test
     * covers the other branch: no matching row found at all, built fresh
     * with its own mocks (rather than reconfiguring the shared ones from
     * setUp() mid-test) so there's no ambiguity about which stub applies.
     */
    public function testResolveSmsTemplateCreatesAnUnpublishedRowWhenNoneExistsForThisText(): void
    {
        $pendingEvent = $this->buildPendingEvent(['text' => 'Hi {{foo}}', 'status' => 'production']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $smsRepository = $this->createMock(ObjectRepository::class);
        $smsRepository->method('findOneBy')->willReturn(null);
        $entityManager->method('getRepository')->with(Sms::class)->willReturn($smsRepository);

        $persisted = [];
        $entityManager->expects($this->exactly(2))->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $subscriber = new SmsCampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->variableResolver,
            $entityManager,
        );

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $subscriber->onSmsSend($pendingEvent);

        $smsRows = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof Sms));
        $this->assertCount(1, $smsRows);
        /** @var Sms $sms */
        $sms = $smsRows[0];
        $this->assertSame('Hi {{foo}}', $sms->getMessage());
        $this->assertFalse($sms->isPublished(false));
        $this->assertStringStartsWith('N8n Dispatch: Hi {{foo}}', $sms->getName());

        $statRows = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof Stat));
        /** @var Stat $stat */
        $stat = $statRows[0];
        $this->assertSame($sms, $stat->getSms());
    }
}
