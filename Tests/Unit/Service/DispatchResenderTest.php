<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\DispatchResender;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener\EnUsTranslator;
use MauticPlugin\N8nDispatchBundle\Twig\StatusExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The rule that decides who can be resent. The resend itself runs the
 * campaign listeners and the database, checked live.
 */
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
     * @return array{DispatchResender, MockObject, LeadEventLog} the resender, its tracker, the log
     */
    private function resenderAnswering(int $status, string $rawBody): array
    {
        $log = new LeadEventLog();
        (new \ReflectionProperty($log, 'id'))->setValue($log, 140);
        $event = new Event();
        $event->setCampaign(new Campaign());
        $event->setType('n8ndispatch.hsm.send');
        $event->setProperties(['status' => 'production']);
        $log->setEvent($event);
        $log->setLead(new Lead());
        $log->setMetadata(['logSendHsmId' => 1, 'n8ndispatch' => ['status' => 'production', 'hsm_router' => 'r', 'response' => ['x' => 1], 'httpStatusCode' => 200]]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($log);

        $settings = $this->createMock(IntegrationSettings::class);
        $settings->method('isPublished')->willReturn(true);
        $integration = $this->createMock(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $integration->method('getKeys')->willReturn(['webhook_url' => 'https://n8n.example.test/hook']);
        $integrations = $this->createMock(IntegrationHelper::class);
        $integrations->method('getIntegrationObject')->willReturn($integration);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getContent')->willReturn($rawBody);
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $dnc = $this->createMock(DncModel::class);
        $dnc->method('isContactable')->willReturn(DoNotContact::IS_CONTACTABLE);

        $tracker = $this->createMock(StatusTracker::class);
        $tracker->method('viewForMetadata')->willReturn([['refType' => 'logSendHsmUuid', 'outcome' => 'error']]);

        $resender = new DispatchResender(
            $em,
            $integrations,
            $http,
            $dnc,
            $tracker,
            $this->createMock(StatusExtension::class),
            new DispatchFailureReasons(new EnUsTranslator()),
            new EnUsTranslator(),
            $this->createMock(LoggerInterface::class),
        );

        return [$resender, $tracker, $log];
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
}
