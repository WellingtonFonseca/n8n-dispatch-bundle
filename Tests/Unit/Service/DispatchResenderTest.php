<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\FailedLeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\DirectEmailSender;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\DispatchResender;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener\EnUsTranslator;
use MauticPlugin\N8nDispatchBundle\Twig\StatusExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The rule that decides who can be resent. The resend itself runs the
 * campaign listeners and the database, checked live.
 */
#[AllowMockObjectsWithoutExpectations]
class DispatchResenderTest extends TestCase
{
    public function testAnErrorCallbackCanBeResent(): void
    {
        $this->assertTrue(DispatchResender::hasErrorCallback([['refType' => 'logSendEmailId', 'outcome' => 'error']]));
    }

    public function testPendingAndSuccessCannot(): void
    {
        $this->assertFalse(DispatchResender::hasErrorCallback([['refType' => 'logSendEmailId', 'outcome' => 'pending']]));
        $this->assertFalse(DispatchResender::hasErrorCallback([['refType' => 'logSendSmsId', 'outcome' => 'success']]));
    }

    public function testHsmOnlyCountsMetaNotTheMirror(): void
    {
        $mirrorError = ['refType' => 'logSendHsmId', 'outcome' => 'error'];
        $metaOk      = ['refType' => 'logSendHsmUuid', 'outcome' => 'success'];
        $metaError   = ['refType' => 'logSendHsmUuid', 'outcome' => 'error'];

        $this->assertFalse(DispatchResender::hasErrorCallback([$mirrorError, $metaOk]));
        $this->assertTrue(DispatchResender::hasErrorCallback([$mirrorError, $metaError]));
    }

    public function testNoCallbackAtAllCannot(): void
    {
        $this->assertFalse(DispatchResender::hasErrorCallback([]));
    }

    public function testTheBodyIsTheNeutralBlockWithoutWhatTheResponseAdded(): void
    {
        $metadata = ['n8ndispatch' => [
            'contact_id'       => 185,
            'contact_phone'    => '+5511999990000',
            'status'           => 'production',
            'variables'        => ['1' => 'Aluno 01'],
            'response'         => ['uuid' => 'x'],
            'httpStatusCode'   => 200,
            'templateCopyHash' => 'abc',
        ]];

        $this->assertSame(
            ['contact_id' => 185, 'contact_phone' => '+5511999990000', 'status' => 'production', 'variables' => ['1' => 'Aluno 01']],
            DispatchResender::bodyOf($metadata)
        );
    }

    public function testThereIsNoBodyToResendForATestDispatchOrNone(): void
    {
        $this->assertNull(DispatchResender::bodyOf(['n8ndispatch' => ['status' => 'test']]));
        $this->assertNull(DispatchResender::bodyOf([]));
    }

    public function testAfterAResendTheNewResponseAndIdsReplaceTheOldOnesAndFailureMarksGo(): void
    {
        $old = [
            'n8ndispatch'    => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['logSendHsmId' => 1, 'uuid' => 'old'], 'templateCopyHash' => 'h'],
            'logSendHsmId'   => 1,
            'logSendHsmUuid' => 'old',
            'failed'         => 1,
            'reason'         => 'N8nDispatch: x',
            'other'          => 'kept',
        ];

        $new = DispatchResender::metadataAfter('hsm', $old, ['status' => 'production', 'x' => 1], 200, '{"logSendHsmId": 2, "uuid": "new"}');

        $this->assertSame(2, $new['logSendHsmId']);
        $this->assertSame('new', $new['logSendHsmUuid']);
        $this->assertSame(['logSendHsmId' => 2, 'uuid' => 'new'], $new['n8ndispatch']['response']);
        $this->assertSame(200, $new['n8ndispatch']['httpStatusCode']);
        $this->assertSame('h', $new['n8ndispatch']['templateCopyHash']);
        $this->assertSame('kept', $new['other']);
        $this->assertArrayNotHasKey('failed', $new);
        $this->assertArrayNotHasKey('reason', $new);
    }

    public function testANonJsonResponseIsKeptAsText(): void
    {
        $new = DispatchResender::metadataAfter('sms', [], ['status' => 'production'], 200, 'plain');

        $this->assertSame('plain', $new['n8ndispatch']['response']);
        $this->assertArrayNotHasKey('logSendSmsId', $new);
    }

    public function testACallN8nRefusedCountsAsFailed(): void
    {
        $this->assertTrue(DispatchResender::isFailed(['n8ndispatch' => ['httpStatusCode' => 400]]));
        $this->assertTrue(DispatchResender::isFailed(['n8ndispatch' => ['httpStatusCode' => 0]]));
        $this->assertTrue(DispatchResender::isFailed(['failed' => 1]));
        $this->assertFalse(DispatchResender::isFailed(['n8ndispatch' => ['httpStatusCode' => 200]]));
        $this->assertFalse(DispatchResender::isFailed([]));
    }

    public function testAFailedResendReplacesTheResponseDropsTheIdsAndKeepsTheNote(): void
    {
        $old = [
            'n8ndispatch'  => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['uuid' => 'old'], 'templateCopyHash' => 'h'],
            'logSendHsmId' => 1,
            'other'        => 'kept',
        ];

        $new = DispatchResender::metadataAfterFailure($old, ['status' => 'production', 'x' => 1], 400, '{"message": "bad"}', 'N8nDispatch: o webhook respondeu HTTP 400.', "Reenvio feito em 02/10/2026 22:00\nPor a@b.com");

        $this->assertSame(400, $new['n8ndispatch']['httpStatusCode']);
        $this->assertSame(['message' => 'bad'], $new['n8ndispatch']['response']);
        $this->assertSame('h', $new['n8ndispatch']['templateCopyHash']);
        $this->assertArrayNotHasKey('logSendHsmId', $new);
        $this->assertSame(1, $new['failed']);
        $this->assertSame('N8nDispatch: o webhook respondeu HTTP 400.', $new['reason']);
        $this->assertStringContainsString('Por a@b.com', $new['n8ndispatch_resend']);
        $this->assertSame('kept', $new['other']);
        $this->assertTrue(DispatchResender::isFailed($new));
    }

    public function testASuccessfulResendClearsTheNoteOfAnEarlierFailedOne(): void
    {
        $new = DispatchResender::metadataAfter('sms', ['failed' => 1, 'reason' => 'x', 'n8ndispatch_resend' => 'old note'], ['status' => 'production'], 200, '{"logSendSmsId": 5}');

        $this->assertArrayNotHasKey('n8ndispatch_resend', $new);
        $this->assertSame(5, $new['logSendSmsId']);
        $this->assertFalse(DispatchResender::isFailed($new));
    }

    public function testResendInfoSaysWhoAndWhenInUtc(): void
    {
        $this->assertSame(
            ['por' => 'tester@example.com', 'em' => '2026-10-03 23:19'],
            DispatchResender::resendInfo('tester@example.com', new \DateTimeImmutable('2026-10-03 23:19:42', new \DateTimeZone('UTC')))
        );
    }

    public function testResendInfoLeavesOutWhoWhenThereIsNoUser(): void
    {
        $this->assertSame(['em' => '2026-10-03 23:19'], DispatchResender::resendInfo(null, new \DateTimeImmutable('2026-10-03 23:19:42', new \DateTimeZone('UTC'))));
        $this->assertSame(['em' => '2026-10-03 23:19'], DispatchResender::resendInfo('', new \DateTimeImmutable('2026-10-03 23:19:42', new \DateTimeZone('UTC'))));
    }

    public function testResponseOfDecodesJsonKeepsTextAndDropsEmpty(): void
    {
        $this->assertSame(['error' => 'x'], DispatchResender::responseOf(' {"error":"x"} '));
        $this->assertSame('Connection refused', DispatchResender::responseOf('Connection refused'));
        $this->assertNull(DispatchResender::responseOf('   '));
    }

    /**
     * @return array{DispatchResender, MockObject, LeadEventLog, MockObject, MockObject, MockObject} the resender, its tracker, the log, the http client, the entity manager, the Do Not Contact model
     */
    private function resenderAnswering(int $status, string $rawBody, bool $published = true, ?array $keys = null, string $eventType = 'n8ndispatch.hsm.send', ?array $metadata = null, ?array $callbacks = null, int $contactable = DoNotContact::IS_CONTACTABLE, ?ResponseInterface $answer = null): array
    {
        $log = new LeadEventLog();
        (new \ReflectionProperty($log, 'id'))->setValue($log, 140);
        $event = new Event();
        $event->setCampaign(new Campaign());
        $event->setType($eventType);
        $event->setProperties(['status' => 'production']);
        $log->setEvent($event);
        $log->setLead(new Lead());
        $log->setMetadata($metadata ?? ['logSendHsmId' => 1, 'n8ndispatch' => ['status' => 'production', 'hsm_router' => 'r', 'response' => ['x' => 1], 'httpStatusCode' => 200]]);
        $log->setIsScheduled(true);
        $log->setDateTriggered(new \DateTime('2020-01-01'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn('missing' === $eventType ? null : $log);

        $settings = $this->createStub(IntegrationSettings::class);
        $settings->method('isPublished')->willReturn($published);
        $integration = $this->createStub(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $integration->method('getKeys')->willReturn($keys ?? ['webhook_url' => 'https://n8n.example.test/hook', 'webhook_token' => 'secret-token']);
        $integrations = $this->createStub(IntegrationHelper::class);
        $integrations->method('getIntegrationObject')->willReturn($integration);

        $response = $answer ?? $this->createMock(ResponseInterface::class);
        if (null === $answer) {
            $response->method('getStatusCode')->willReturn($status);
            $response->method('getContent')->willReturn($rawBody);
        }
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $dnc = $this->createMock(DncModel::class);
        $dnc->method('isContactable')->willReturn($contactable);

        $tracker = $this->createMock(StatusTracker::class);
        $tracker->method('viewForMetadata')->willReturn($callbacks ?? [['refType' => 'logSendHsmUuid', 'outcome' => 'error']]);

        $dates = $this->createStub(StatusExtension::class);
        $dates->method('date')->willReturn('DATE');

        $resender = new DispatchResender(
            $em,
            $integrations,
            $http,
            $dnc,
            $tracker,
            $dates,
            new DispatchFailureReasons(new EnUsTranslator()),
            new EnUsTranslator(),
            $this->createMock(LoggerInterface::class),
        );

        return [$resender, $tracker, $log, $http, $em, $dnc];
    }

    public function testARefusedResendKeepsWhoDidItAndTheWholeAnswer(): void
    {
        [$resender, $tracker] = $this->resenderAnswering(400, '{"body":{"error":"Missing header"},"statusCode":400}');

        $tracker->expects($this->once())->method('recordDispatch')->with(
            140,
            false,
            [],
            ['body' => ['error' => 'Missing header'], 'statusCode' => 400],
            null,
            $this->callback(fn (array $resend): bool => 'admin@example.com' === ($resend['por'] ?? null) && 1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $resend['em'] ?? ''))
        );

        $this->assertFalse($resender->resend(140, 'admin@example.com')['ok']);
    }

    public function testARefusedResendReportsN8nsOwnErrorText(): void
    {
        [$resender] = $this->resenderAnswering(400, '{"error":"Missing header"}');

        $result = $resender->resend(140, 'admin@example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame('The resend failed: N8nDispatch: Missing header', $result['message']);
    }

    public function testARefusedResendWithoutAnErrorTextReportsTheHttpStatus(): void
    {
        [$resender] = $this->resenderAnswering(502, 'Bad Gateway');

        $result = $resender->resend(140, 'admin@example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame('The resend failed: N8nDispatch: webhook returned HTTP 502.', $result['message']);
    }

    public function testResendFailsWhenTheIntegrationIsDisabled(): void
    {
        [$resender, , , $http] = $this->resenderAnswering(200, '{}', false);
        $http->expects($this->never())->method('request');

        $result = $resender->resend(140, 'admin@example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame('The resend failed: N8nDispatch: the N8N Dispatch integration is not configured/enabled.', $result['message']);
    }

    public function testResendFailsWhenTheWebhookUrlIsMissing(): void
    {
        [$resender, , , $http] = $this->resenderAnswering(200, '{}', true, ['webhook_url' => '']);
        $http->expects($this->never())->method('request');

        $result = $resender->resend(140, 'admin@example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame('The resend failed: N8nDispatch: webhook_url is not configured.', $result['message']);
    }

    public function testTheResendCallsTheWebhookWithTheActionAndTheTokenHeaders(): void
    {
        [$resender, , , $http] = $this->resenderAnswering(200, '{"logSendHsmId":1}');
        $http->expects($this->once())->method('request')->with('POST', 'https://n8n.example.test/hook', $this->callback(function (array $options): bool {
            $this->assertSame('hsm.send', $options['headers']['X-N8n-Dispatch-Action']);
            $this->assertSame('secret-token', $options['headers']['X-N8n-Dispatch-Token']);

            return true;
        }))->willReturn($this->createStub(ResponseInterface::class));

        $resender->resend(140, 'admin@example.com');
    }

    public function testAnAcceptedResendKeepsWhoDidItAndTheWholeAnswer(): void
    {
        [$resender, $tracker] = $this->resenderAnswering(200, '{"logSendHsmId":777,"uuid":"u-777"}');

        $tracker->expects($this->once())->method('recordDispatch')->with(
            140,
            true,
            ['logSendHsmId' => 777, 'logSendHsmUuid' => 'u-777'],
            ['logSendHsmId' => 777, 'uuid' => 'u-777'],
            null,
            $this->callback(fn (array $resend): bool => 'admin@example.com' === ($resend['por'] ?? null))
        );

        $this->assertTrue($resender->resend(140, 'admin@example.com')['ok']);
    }

    public function testTheStatusBoundariesOfAFailedCall(): void
    {
        $this->assertTrue(DispatchResender::isFailed(['n8ndispatch' => ['httpStatusCode' => 199]]));
        $this->assertFalse(DispatchResender::isFailed(['n8ndispatch' => ['httpStatusCode' => 299]]));
        $this->assertTrue(DispatchResender::isFailed(['n8ndispatch' => ['httpStatusCode' => 300]]));
    }

    public function testTheErrorsOfTheOldDispatchGoAfterAResendAcceptedOrRefused(): void
    {
        $old = ['errors' => ['x'], 'n8ndispatch' => ['status' => 'production']];

        $this->assertArrayNotHasKey('errors', DispatchResender::metadataAfter('sms', $old, ['status' => 'production'], 200, '{}'));
        $this->assertArrayNotHasKey('errors', DispatchResender::metadataAfterFailure($old, ['status' => 'production'], 400, '{}', 'r', 'n'));
    }

    public function testAnUnknownLogOrAnEventOfAnotherPluginIsNotFound(): void
    {
        [$resender] = $this->resenderAnswering(200, '{}', true, null, 'missing');
        $this->assertSame(['ok' => false, 'message' => 'Dispatch not found.'], $resender->resend(1, 'a@b.com'));

        [$resender, , , $http] = $this->resenderAnswering(200, '{}', true, null, 'campaign.sendemail');
        $http->expects($this->never())->method('request');
        $this->assertSame(['ok' => false, 'message' => 'Dispatch not found.'], $resender->resend(140, 'a@b.com'));
    }

    /** @var array<string, mixed> the id each class was looked up by */
    private array $found = [];

    /**
     * The resender of an Email sent through Mautic's own mailer.
     *
     * @param array<string, mixed>                                  $metadata
     * @param array{outcome: string, reason: ?string, statId: ?int} $answer  what the direct sender answers
     *
     * @return array{DispatchResender, LeadEventLog, MockObject, MockObject, MockObject} the resender, the log, the sender, the entity manager, the http client
     */
    private function directResender(array $metadata, array $answer = ['outcome' => 'sent', 'reason' => null, 'statId' => 502], ?Email $email = null, bool $withSender = true, string $status = 'production', int $contactable = DoNotContact::IS_CONTACTABLE, bool $emailGone = false): array
    {
        $log = new LeadEventLog();
        (new \ReflectionProperty($log, 'id'))->setValue($log, 140);
        $event = new Event();
        (new \ReflectionProperty($event, 'id'))->setValue($event, 66);
        $event->setCampaign(new Campaign());
        $event->setType('n8ndispatch.email.send');
        $event->setProperties(['status' => $status, 'email' => 7]);
        $log->setEvent($event);
        $log->setLead(new Lead());
        $log->setMetadata($metadata);
        $log->setIsScheduled(true);
        $log->setDateTriggered(new \DateTime('2020-01-01'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(function (string $class, $id) use ($log, $emailGone, $email) {
            $this->found[$class] = $id;

            return LeadEventLog::class === $class ? $log : ($emailGone ? null : ($email ?? new Email()));
        });

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');

        $dnc = $this->createMock(DncModel::class);
        $dnc->method('isContactable')->willReturn($contactable);

        $dates = $this->createMock(StatusExtension::class);
        $dates->method('date')->willReturn('DATE');

        $sender = $this->createMock(DirectEmailSender::class);
        $sender->method('send')->willReturn($answer);

        $resender = new DispatchResender(
            $em,
            $this->createMock(IntegrationHelper::class),
            $http,
            $dnc,
            $this->createMock(StatusTracker::class),
            $dates,
            new DispatchFailureReasons(new EnUsTranslator()),
            new EnUsTranslator(),
            $this->createMock(LoggerInterface::class),
            $withSender ? $sender : null,
        );

        return [$resender, $log, $sender, $em, $http];
    }

    /**
     * @return array<string, mixed>
     */
    private function refusedByMautic(array $extra = []): array
    {
        return ['failed' => 1, 'reason' => 'N8nDispatch: 550 mailbox unavailable', 'n8ndispatch' => ['mode' => 'mautic', 'status' => 'production', 'mautic_template_id' => 7, 'contact_email' => 'a@b.com', 'variables' => ['n8n_nome' => 'Zeca'], 'outcome' => 'failed', 'statId' => null]] + $extra;
    }

    public function testAnEmailMauticRefusedIsSentAgainThroughTheMailerWithTheValuesOfTheFirstSend(): void
    {
        $email = new Email();
        [$resender, $log, $sender, , $http] = $this->directResender($this->refusedByMautic(), ['outcome' => 'sent', 'reason' => null, 'statId' => 502], $email);
        $sender->expects($this->once())->method('send')->with($log->getLead(), $email, 66, ['n8n_nome' => 'Zeca']);
        $http->expects($this->never())->method('request');

        $result = $resender->resend(140, 'a@b.com');

        $this->assertSame(['ok' => true, 'message' => 'Resent.'], $result);
        $this->assertSame([LeadEventLog::class => 140, Email::class => 7], $this->found, 'the Email of the step, by its id');
    }

    public function testAnAcceptedDirectResendClearsTheFailureAndRecordsTheNewStatAndWhoDidIt(): void
    {
        [$resender, $log, , $em] = $this->directResender($this->refusedByMautic(['errors' => ['x']]));
        $failed = new FailedLeadEventLog();
        $failed->setLog($log);
        $log->setFailedLog($failed);
        $em->expects($this->once())->method('remove')->with($failed);
        $em->expects($this->once())->method('persist')->with($log);
        $em->expects($this->once())->method('flush');

        $resender->resend(140, 'a@b.com');

        $metadata = $log->getMetadata();
        $this->assertArrayNotHasKey('failed', $metadata);
        $this->assertArrayNotHasKey('reason', $metadata);
        $this->assertArrayNotHasKey('errors', $metadata);
        $this->assertSame('sent', $metadata['n8ndispatch']['outcome']);
        $this->assertSame(502, $metadata['n8ndispatch']['statId']);
        $this->assertArrayNotHasKey('statIds', $metadata['n8ndispatch'], 'one attempt needs no list');
        $this->assertSame(['Resent on DATE', 'By a@b.com'], explode("\n", $metadata['n8ndispatch_resend']));
        $this->assertSame('Zeca', $metadata['n8ndispatch']['variables']['n8n_nome']);
        $this->assertFalse($log->getIsScheduled());
        $this->assertGreaterThan(new \DateTime('2020-01-01'), $log->getDateTriggered());
        $this->assertNull($log->getFailedLog());
    }

    public function testEveryAttemptKeepsItsStatInTheList(): void
    {
        $metadata                           = $this->refusedByMautic();
        $metadata['n8ndispatch']['statId']  = 300;
        $metadata['n8ndispatch']['statIds'] = [300, 400];
        [$resender, $log]                   = $this->directResender($metadata, ['outcome' => 'sent', 'reason' => null, 'statId' => 502]);

        $resender->resend(140, null);

        $this->assertSame(300, $log->getMetadata()['n8ndispatch']['statId']);
        $this->assertSame([300, 400, 502], $log->getMetadata()['n8ndispatch']['statIds']);
        $this->assertSame('Resent on DATE', $log->getMetadata()['n8ndispatch_resend'], 'nobody to name');
    }

    public function testASecondStatJoinsTheFirstWhenTheFirstSendLeftNone(): void
    {
        [$resender, $log] = $this->directResender($this->refusedByMautic());

        $resender->resend(140, null);

        $this->assertSame(502, $log->getMetadata()['n8ndispatch']['statId']);
    }

    public function testAnEmailTheMailerHeldBackPassesTheResendWithNoNewStat(): void
    {
        [$resender, $log] = $this->directResender($this->refusedByMautic(), ['outcome' => 'queued', 'reason' => null, 'statId' => null]);

        $result = $resender->resend(140, null);

        $this->assertTrue($result['ok']);
        $this->assertSame('queued', $log->getMetadata()['n8ndispatch']['outcome']);
        $this->assertNull($log->getMetadata()['n8ndispatch']['statId']);
        $this->assertArrayNotHasKey('failed', $log->getMetadata());
    }

    public function testAResendTheMailerRefusesAgainIsRecordedAndStaysResendable(): void
    {
        [$resender, $log, , $em] = $this->directResender($this->refusedByMautic(), ['outcome' => 'failed', 'reason' => 'N8nDispatch: 550 again', 'statId' => null]);
        $failed = new FailedLeadEventLog();
        $failed->setLog($log);
        $log->setFailedLog($failed);
        $em->expects($this->never())->method('remove');
        $em->expects($this->once())->method('flush');

        $result = $resender->resend(140, 'a@b.com');

        $this->assertSame(['ok' => false, 'message' => 'The resend failed: N8nDispatch: 550 again'], $result);
        $metadata = $log->getMetadata();
        $this->assertSame(1, $metadata['failed']);
        $this->assertSame('N8nDispatch: 550 again', $metadata['reason']);
        $this->assertSame('failed', $metadata['n8ndispatch']['outcome']);
        $this->assertSame('Resent on DATE'."\n".'By a@b.com', $metadata['n8ndispatch_resend']);
        $this->assertSame($failed, $log->getFailedLog());
        $this->assertFalse($log->getIsScheduled());
    }

    public function testAContactLeftOutOnTheResendIsReportedAndRecorded(): void
    {
        [$resender, $log] = $this->directResender($this->refusedByMautic(), ['outcome' => 'skipped', 'reason' => 'N8nDispatch: the contact has no email address.', 'statId' => null]);

        $result = $resender->resend(140, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('skipped', $log->getMetadata()['n8ndispatch']['outcome']);
        $this->assertSame(1, $log->getMetadata()['failed']);
    }

    public function testOnlyAStepInProductionIsResentThroughMautic(): void
    {
        [$resender, , $sender] = $this->directResender($this->refusedByMautic(), status: 'paused');
        $sender->expects($this->never())->method('send');

        $this->assertSame(['ok' => false, 'message' => 'The campaign step is not in production.'], $resender->resend(140, null));
    }

    public function testOnlyASendTheMailerRefusedIsResentThroughMautic(): void
    {
        foreach ([
            ['n8ndispatch' => ['mode' => 'mautic', 'status' => 'production', 'outcome' => 'sent', 'statId' => 3]],
            ['failed' => 1, 'n8ndispatch' => ['mode' => 'mautic', 'status' => 'production', 'outcome' => 'skipped', 'statId' => null]],
            ['n8ndispatch' => ['mode' => 'mautic', 'status' => 'production', 'outcome' => 'failed', 'statId' => null]],
        ] as $metadata) {
            [$resender, , $sender] = $this->directResender($metadata);
            $sender->expects($this->never())->method('send');

            $this->assertSame(['ok' => false, 'message' => "Only a send that Mautic's mailer refused can be resent."], $resender->resend(140, null));
        }
    }

    public function testWithoutTheDirectSenderThereIsNothingToResendWith(): void
    {
        [$resender] = $this->directResender($this->refusedByMautic(), withSender: false);

        $this->assertSame(['ok' => false, 'message' => 'Resending an Email sent through Mautic is not available yet.'], $resender->resend(140, null));
    }

    public function testADeletedOrUnpublishedEmailIsReportedNotSent(): void
    {
        $unpublished = new Email();
        $unpublished->setIsPublished(false);
        [$resender, , $sender] = $this->directResender($this->refusedByMautic(), email: $unpublished);
        $sender->expects($this->never())->method('send');
        $this->assertSame(['ok' => false, 'message' => 'The resend failed: N8nDispatch: Email #0 is not published.'], $resender->resend(140, null));

        [$resender, , $sender] = $this->directResender($this->refusedByMautic(), emailGone: true);
        $sender->expects($this->never())->method('send');
        $this->assertSame(['ok' => false, 'message' => 'The resend failed: N8nDispatch: Email #7 of this step was deleted.'], $resender->resend(140, null));
    }

    public function testTheDoNotContactListStopsADirectResendToo(): void
    {
        [$resender, $log, $sender, $em] = $this->directResender($this->refusedByMautic(), contactable: DoNotContact::UNSUBSCRIBED);
        $sender->expects($this->never())->method('send');
        $em->expects($this->never())->method('flush');

        $result = $resender->resend(140, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('The resend failed: N8nDispatch: contact is on the Do Not Contact list for email.', $result['message']);
        $this->assertSame(1, $log->getMetadata()['failed']);
        $this->assertSame('2020-01-01', $log->getDateTriggered()->format('Y-m-d'), 'nothing recorded when nothing was sent');
    }

    public function testAStepNotInProductionIsNotResent(): void
    {
        [$resender, , $log, $http] = $this->resenderAnswering(200, '{}');
        $log->getEvent()->setProperties(['status' => 'test']);
        $http->expects($this->never())->method('request');

        $this->assertSame(['ok' => false, 'message' => 'The campaign step is not in production.'], $resender->resend(140, 'a@b.com'));
    }

    public function testADispatchWithoutErrorCallbackNorFailureIsNotResent(): void
    {
        [$resender, , , $http] = $this->resenderAnswering(200, '{}', true, null, 'n8ndispatch.hsm.send', null, []);
        $http->expects($this->never())->method('request');

        $this->assertSame(['ok' => false, 'message' => 'The callback is not in error.'], $resender->resend(140, 'a@b.com'));
    }

    public function testAFailedCallWithoutErrorCallbackIsResent(): void
    {
        $metadata = ['failed' => 1, 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 500]];
        [$resender, , , $http] = $this->resenderAnswering(200, '{"logSendHsmId":9}', true, null, 'n8ndispatch.hsm.send', $metadata, []);
        $http->expects($this->once())->method('request');

        $this->assertTrue($resender->resend(140, 'a@b.com')['ok']);
    }

    public function testADispatchWithoutSavedBodyIsNotResent(): void
    {
        [$resender, , , $http] = $this->resenderAnswering(200, '{}', true, null, 'n8ndispatch.hsm.send', ['failed' => 1]);
        $http->expects($this->never())->method('request');

        $this->assertSame(['ok' => false, 'message' => 'The dispatch has no saved body to resend.'], $resender->resend(140, 'a@b.com'));
    }

    public function testTheDoNotContactListIsAskedByChannelAndStopsTheResend(): void
    {
        foreach (['n8ndispatch.email.send' => 'email', 'n8ndispatch.sms.send' => 'sms', 'n8ndispatch.hsm.send' => 'sms'] as $type => $channel) {
            [$resender, , , $http, , $dnc] = $this->resenderAnswering(200, '{}', true, null, $type, null, null, DoNotContact::UNSUBSCRIBED);
            $dnc->expects($this->once())->method('isContactable')->with($this->anything(), $channel);
            $http->expects($this->never())->method('request');

            $result = $resender->resend(140, 'a@b.com');

            $this->assertFalse($result['ok']);
            $this->assertSame('The resend failed: N8nDispatch: contact is on the Do Not Contact list for '.$channel.'.', $result['message']);
        }
    }

    public function testTheAnswerIsReadWithoutThrowingOnHttpErrors(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('getContent')->with(false)->willReturn('{"logSendHsmId":1}');
        [$resender] = $this->resenderAnswering(200, '', true, null, 'n8ndispatch.hsm.send', null, null, DoNotContact::IS_CONTACTABLE, $response);

        $this->assertTrue($resender->resend(140, 'a@b.com')['ok']);
    }

    public function testStatus300IsARefusalAndStatus299IsNot(): void
    {
        [$resender] = $this->resenderAnswering(300, '{"logSendHsmId":1}');
        $this->assertFalse($resender->resend(140, 'a@b.com')['ok']);

        [$resender] = $this->resenderAnswering(299, '{"logSendHsmId":1}');
        $this->assertTrue($resender->resend(140, 'a@b.com')['ok']);
    }

    public function testAnEmptyAnswerFailsOnlyForHsm(): void
    {
        foreach (['', "  \n"] as $empty) {
            [$resender] = $this->resenderAnswering(200, $empty);
            $result = $resender->resend(140, 'a@b.com');
            $this->assertFalse($result['ok']);
            $this->assertSame('The resend failed: N8nDispatch: n8n returned an empty response for hsm.send.', $result['message']);
        }

        [$resender] = $this->resenderAnswering(200, '', true, null, 'n8ndispatch.sms.send', ['failed' => 1, 'n8ndispatch' => ['status' => 'production']]);
        $this->assertTrue($resender->resend(140, 'a@b.com')['ok']);
    }

    public function testARefusedWithEmptyAnswerNamesTheActionOfTheChannel(): void
    {
        [$resender] = $this->resenderAnswering(500, " ", true, null, 'n8ndispatch.email.send', ['failed' => 1, 'n8ndispatch' => ['status' => 'production']]);

        $this->assertSame('The resend failed: N8nDispatch: n8n returned an empty response for email.send.', $resender->resend(140, 'a@b.com')['message']);
    }

    public function testAnAcceptedResendUpdatesTheLogAndTheTracking(): void
    {
        [$resender, $tracker, $log, , $em] = $this->resenderAnswering(200, '{"logSendHsmId":777,"uuid":"u-777"}');
        $old = $log->getMetadata();

        $em->expects($this->once())->method('persist')->with($log);
        $em->expects($this->once())->method('flush');
        $tracker->expects($this->once())->method('forget')->with($old, 140);
        $tracker->expects($this->once())->method('register')->with('hsm', ['logSendHsmId' => 777, 'logSendHsmUuid' => 'u-777'], null, 140);
        $tracker->expects($this->once())->method('annotate')->with(
            $this->callback(fn (array $m): bool => 777 === $m['logSendHsmId']),
            $this->callback(fn (array $message): bool => 'a@b.com' === $message['reenvio']['por'] && 'DATE' === $message['reenvio']['em'])
        );

        $result = $resender->resend(140, 'a@b.com');

        $this->assertSame(['ok' => true, 'message' => 'Resent.'], $result);
        $this->assertSame(777, $log->getMetadata()['logSendHsmId']);
        $this->assertFalse($log->getIsScheduled());
        $this->assertGreaterThan(new \DateTime('2020-01-01'), $log->getDateTriggered());
    }

    public function testAnAcceptedResendRemovesCoresFailedLogSoTheTimelineStopsShowingTheOldError(): void
    {
        [$resender, , $log, , $em] = $this->resenderAnswering(200, '{"logSendHsmId":777,"uuid":"u-777"}');
        $failed = new FailedLeadEventLog();
        $failed->setLog($log);
        $failed->setReason('HTTP 404');
        $log->setFailedLog($failed);

        $em->expects($this->once())->method('remove')->with($failed);

        $this->assertTrue($resender->resend(140, 'a@b.com')['ok']);
        $this->assertNull($log->getFailedLog());
    }

    public function testAnAcceptedResendOfALogWithoutAFailedLogRemovesNothing(): void
    {
        [$resender, , , , $em] = $this->resenderAnswering(200, '{"logSendHsmId":777,"uuid":"u-777"}');

        $em->expects($this->never())->method('remove');

        $this->assertTrue($resender->resend(140, 'a@b.com')['ok']);
    }

    public function testARefusedResendKeepsCoresFailedLog(): void
    {
        [$resender, , $log, , $em] = $this->resenderAnswering(404, '{"error":"not found"}');
        $failed = new FailedLeadEventLog();
        $failed->setLog($log);
        $log->setFailedLog($failed);

        $em->expects($this->never())->method('remove');

        $this->assertFalse($resender->resend(140, 'a@b.com')['ok']);
        $this->assertSame($failed, $log->getFailedLog());
    }

    public function testARefusedResendUpdatesTheLogAndForgetsTheOldTracking(): void
    {
        [$resender, $tracker, $log, , $em] = $this->resenderAnswering(400, '{"error":"bad"}');
        $old = $log->getMetadata();

        $em->expects($this->once())->method('persist')->with($log);
        $em->expects($this->once())->method('flush');
        $tracker->expects($this->once())->method('forget')->with($old, 140);

        $resender->resend(140, 'a@b.com');

        $this->assertSame(400, $log->getMetadata()['n8ndispatch']['httpStatusCode']);
        $this->assertFalse($log->getIsScheduled());
        $this->assertGreaterThan(new \DateTime('2020-01-01'), $log->getDateTriggered());
    }

    public function testTheNoteSaysWhenAndByWhomAndLeavesOutAnEmptyUser(): void
    {
        $notes = [];
        foreach (['a@b.com', null, ''] as $user) {
            [$resender, , $log] = $this->resenderAnswering(400, '{}');
            $resender->resend(140, $user);
            $notes[] = $log->getMetadata()['n8ndispatch_resend'];
        }

        $this->assertSame("Resent on DATE\nBy a@b.com", $notes[0]);
        $this->assertSame('Resent on DATE', $notes[1]);
        $this->assertSame('Resent on DATE', $notes[2]);
    }
}
