<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\EmailBundle\Entity\Copy;
use Mautic\EmailBundle\Entity\CopyRepository;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\EventListener\CampaignTriggerSubscriber;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use MauticPlugin\N8nDispatchBundle\UnsubscribeVariable;
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

    private CopyRepository $copyRepository;

    private MailHashHelper $mailHashHelper;

    private EntityManagerInterface $entityManager;

    private CampaignTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->emailModel        = $this->createMock(EmailModel::class);
        $this->variableResolver  = $this->createMock(VariableResolver::class);
        $this->copyRepository    = $this->createMock(CopyRepository::class);
        // MailHashHelper is final, so it can't be doubled — build a real
        // instance off a mocked CoreParametersHelper instead, giving a
        // deterministic, real hash_hmac() result tests can still assert on.
        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelper->method('get')->with('secret_key')->willReturn('test-secret-key');
        $this->mailHashHelper = new MailHashHelper($coreParametersHelper);
        $this->entityManager  = $this->createMock(EntityManagerInterface::class);

        $this->subscriber = new CampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->emailModel,
            $this->variableResolver,
            $this->mailHashHelper,
            $this->entityManager,
        );

        $this->variableResolver->method('resolveAll')->willReturn(['foo' => 'bar']);
        // Every 'production' test fetches an Email and, through
        // saveTemplateCopy(), calls the CopyRepository — left unconfigured
        // by default (PHPUnit stubs return null for both methods), which
        // saveTemplateCopy() already treats as "couldn't snapshot, that's
        // fine". Tests specifically about the snapshot configure their own
        // expectations on $this->copyRepository.
        $this->emailModel->method('getCopyRepository')->willReturn($this->copyRepository);
        // Whenever saveTemplateCopy() did resolve a hash, buildUnsubscribeUrl()
        // calls getReference() to link the Stat to it. Left unconfigured here
        // deliberately — a stub added in setUp() would be matched ahead of a
        // more specific with()-constrained one added later in an individual
        // test (PHPUnit checks configured behaviors in registration order),
        // which would make that test's own assertion moot. Tests that reach
        // this call configure their own expectation instead.
        // Every 'production' test also goes through buildUnsubscribeUrl(),
        // which calls EmailModel::buildUrl() and casts the result to
        // string — so an unconfigured call (PHPUnit stubs return null)
        // degrades to '' rather than a TypeError, and tests that need a
        // specific URL can configure their own expectation without
        // fighting a blanket default registered here first.
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

    public function testProductionStatusSavesAndAttachesATemplateCopySnapshot(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $email = new Email();
        $email->setSubject('Welcome');
        $email->setCustomHtml('<html><body>Hi {{aluno_nome}}</body></html>');
        $this->emailModel->method('getEntity')->with(1)->willReturn($email);
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $expectedHash = md5('Welcome<html><body>Hi {{aluno_nome}}</body></html>');

        $this->copyRepository->expects($this->once())->method('findByHash')->with($expectedHash)->willReturn(null);
        $this->copyRepository->expects($this->once())->method('saveCopy')
            ->with($expectedHash, 'Welcome', '<html><body>Hi {{aluno_nome}}</body></html>', '')
            ->willReturn(true);
        $this->entityManager->method('getReference')->willReturn(new Copy());

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        /** @var LeadEventLog $passedLog */
        $passedLog = $pendingEvent->getSuccessful()->first();
        $this->assertSame($expectedHash, $passedLog->getMetadata()['n8ndispatch']['templateCopyHash']);
    }

    public function testProductionStatusReusesAnExistingTemplateCopyInsteadOfSavingAgain(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $this->copyRepository->method('findByHash')->willReturn(new Copy());
        $this->copyRepository->expects($this->never())->method('saveCopy');
        $this->entityManager->method('getReference')->willReturn(new Copy());

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        /** @var LeadEventLog $passedLog */
        $passedLog = $pendingEvent->getSuccessful()->first();
        $this->assertArrayHasKey('templateCopyHash', $passedLog->getMetadata()['n8ndispatch']);
    }

    public function testProductionStatusCreatesAStatAndIncludesTheUnsubscribeUrlInVariables(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $email = new Email();
        $this->emailModel->method('getEntity')->with(1)->willReturn($email);
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $this->emailModel->expects($this->once())->method('saveEmailStat')
            ->with($this->callback(function (Stat $stat) use ($email): bool {
                $this->assertSame($email, $stat->getEmail());
                $this->assertSame('contact@example.test', $stat->getEmailAddress());
                $this->assertNotEmpty($stat->getTrackingHash());

                return true;
            }));

        $this->emailModel->expects($this->once())->method('buildUrl')
            ->with('mautic_email_unsubscribe', $this->callback(function (array $params) {
                $this->assertSame('contact@example.test', $params['urlEmail']);
                $this->assertSame($this->mailHashHelper->getEmailHash('contact@example.test'), $params['secretHash']);
                $this->assertNotEmpty($params['idHash']);

                return true;
            }))
            ->willReturn('https://mautic.example.test/email/unsubscribe/xyz');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame(
                        'https://mautic.example.test/email/unsubscribe/xyz',
                        $options['json']['variables'][UnsubscribeVariable::KEY]
                    );

                    return true;
                })
            )
            ->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
    }

    public function testProductionStatusLinksTheCreatedStatToTheTemplateCopySoTheNativeViewLinkWorks(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $email = new Email();
        $email->setSubject('Welcome');
        $email->setCustomHtml('<html><body>Hi</body></html>');
        $this->emailModel->method('getEntity')->with(1)->willReturn($email);
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $expectedHash = md5('Welcome<html><body>Hi</body></html>');
        $this->copyRepository->method('findByHash')->with($expectedHash)->willReturn(null);
        $this->copyRepository->method('saveCopy')->willReturn(true);

        $copyReference = new Copy();
        $this->entityManager->expects($this->once())->method('getReference')
            ->with(Copy::class, $expectedHash)
            ->willReturn($copyReference);

        $this->emailModel->expects($this->once())->method('saveEmailStat')
            ->with($this->callback(function (Stat $stat) use ($copyReference): bool {
                $this->assertSame($copyReference, $stat->getStoredCopy());

                return true;
            }));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
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
                'variables'          => [
                    'foo'                     => 'bar',
                    UnsubscribeVariable::KEY => '(not generated — no real dispatch)',
                ],
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

    public function testFailedDispatchStillRecordsTheFullResponseOnTheTimeline(): void
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

        // A failed dispatch never actually reached the contact — it must
        // not create the Stat that backs core's native Sent-Email Timeline
        // entry, or the contact's history would show "email sent" for an
        // email that never went out.
        $this->emailModel->expects($this->never())->method('saveEmailStat');

        $this->subscriber->onEmailSend($pendingEvent);

        $failures = $pendingEvent->getFailures();
        $this->assertCount(1, $failures);
        /** @var LeadEventLog $failedLog */
        $failedLog = $failures->first();
        $metadata  = $failedLog->getMetadata();

        // Not just present — the Body/Response JSON block dumps this as-is.
        $this->assertSame(404, $metadata['n8ndispatch']['response']['statusCode']);
        $this->assertSame('Not Found', $metadata['n8ndispatch']['response']['statusMessage']);
        // The Timeline card's outcome badge keys off this instead — the
        // real HTTP transport status, not the response body's own nested
        // field (see testHttpStatusCodeDrivesTheOutcomeBadgeEvenWhenTheResponseBodyDisagrees).
        $this->assertSame(404, $metadata['n8ndispatch']['httpStatusCode']);
        // PendingEvent::fail()'s own array_merge() must not have clobbered it.
        $this->assertSame(1, $metadata['failed']);
    }

    public function testHttpStatusCodeDrivesTheOutcomeBadgeEvenWhenTheResponseBodyDisagrees(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        // Real transport status is 2xx (this listener's own pass/fail
        // decision treats this as a success), but the mocked response
        // body's own nested 'statusCode' field is stale/out of sync — a
        // real scenario hit live: an n8n test workflow had its outer HTTP
        // status updated to 2xx without updating the mocked body to match.
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn(
            '{"statusCode":404,"statusMessage":"Not Found"}'
        );
        $this->httpClient->method('request')->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
        /** @var LeadEventLog $passedLog */
        $passedLog = $pendingEvent->getSuccessful()->first();
        $metadata  = $passedLog->getMetadata();

        // The outcome badge's field reflects reality (a pass) ...
        $this->assertSame(200, $metadata['n8ndispatch']['httpStatusCode']);
        // ... even though the raw response dump still shows the
        // disagreeing body field, unmodified, for debugging.
        $this->assertSame(404, $metadata['n8ndispatch']['response']['statusCode']);
    }

    public function testFailedDispatchStillSendsAnUnsubscribeUrlButNeverPersistsItsStat(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'production']);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        // buildUrl() only generates a route string — it never touches the
        // database — so the unsubscribe URL still goes out in the payload
        // even for a dispatch that turns out to fail, same as a successful
        // one. Only saveEmailStat() (asserted never-called below) is what
        // would actually persist anything, and that's gated on success.
        $this->emailModel->expects($this->once())->method('buildUrl')
            ->with('mautic_email_unsubscribe', $this->anything())
            ->willReturn('https://mautic.example.test/email/unsubscribe/xyz');
        $this->emailModel->expects($this->never())->method('saveEmailStat');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->with(false)->willReturn('not valid json');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame(
                        'https://mautic.example.test/email/unsubscribe/xyz',
                        $options['json']['variables'][UnsubscribeVariable::KEY]
                    );

                    return true;
                })
            )
            ->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getFailures());
    }
}
