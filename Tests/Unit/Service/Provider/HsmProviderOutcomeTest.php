<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderOutcome;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendResult;
use PHPUnit\Framework\TestCase;

class HsmProviderOutcomeTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function metadataProvider(): array
    {
        return [
            'a dispatch through the DialogHSM'   => [['n8ndispatch' => ['mode' => 'dialoghsm', 'status' => 'production']], true],
            'a test step through the DialogHSM'  => [['n8ndispatch' => ['mode' => 'dialoghsm', 'status' => 'test']], true],
            'a dispatch of n8n (no mode)'         => [['n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]], false],
            'an Email through the mailer'         => [['n8ndispatch' => ['mode' => 'mautic']], false],
            'a log with no card'                  => [[], false],
            'a card that is not a list'           => [['n8ndispatch' => 'x'], false],
        ];
    }

    /**
     * @dataProvider metadataProvider
     *
     * @param array<string, mixed> $metadata
     */
    public function testItTellsADispatchThroughTheProviderByItsMode(array $metadata, bool $expected): void
    {
        $this->assertSame($expected, HsmProviderOutcome::isProviderDispatch($metadata));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'sent'                  => [['n8ndispatch' => ['mode' => 'dialoghsm', 'outcome' => 'sent']], 'sent'],
            'failed'                => [['n8ndispatch' => ['mode' => 'dialoghsm', 'outcome' => 'failed']], 'failed'],
            'a test step, no send'  => [['n8ndispatch' => ['mode' => 'dialoghsm', 'status' => 'test']], null],
            'a value of nobody'     => [['n8ndispatch' => ['mode' => 'dialoghsm', 'outcome' => 'queued']], null],
            'not a provider card'   => [['n8ndispatch' => ['mode' => 'mautic', 'outcome' => 'sent']], null],
            'n8n card'              => [['n8ndispatch' => ['outcome' => 'sent']], null],
        ];
    }

    /**
     * @dataProvider outcomeProvider
     *
     * @param array<string, mixed> $metadata
     */
    public function testTheOutcomeIsSentOrFailedOnlyForAProviderCardThatWasSent(array $metadata, ?string $expected): void
    {
        $this->assertSame($expected, HsmProviderOutcome::outcomeOf($metadata));
    }

    private function request(): HsmSendRequest
    {
        return new HsmSendRequest(7, '+5511999990001', 'pt_BR', 'welcome_v1', 'text', ['1' => 'Ana'], [], 3, 11, 22);
    }

    public function testTheCardOfAMessageNotSentYetHasNoOutcome(): void
    {
        $card = HsmProviderOutcome::card($this->request(), 'test', null);

        $this->assertSame(['mode' => 'dialoghsm', 'status' => 'test', 'request' => $this->request()->toArray()], $card);
    }

    public function testTheCardKeepsTheAnswerOfTheProvider(): void
    {
        $sent   = HsmProviderOutcome::card($this->request(), 'production', HsmSendResult::accepted('wamid.X', 200, null));
        $failed = HsmProviderOutcome::card($this->request(), 'production', HsmSendResult::refused('no', 400));

        $this->assertSame('sent', $sent['outcome']);
        $this->assertSame('wamid.X', $sent['wamid']);
        $this->assertTrue($sent['result']['accepted']);
        $this->assertSame('failed', $failed['outcome']);
        $this->assertSame('no', $failed['result']['error']);
    }

    public function testAnAcceptedResendReplacesTheCardAndClearsTheFailureMarks(): void
    {
        $old = ['failed' => 1, 'reason' => 'x', 'errors' => ['e'], 'n8ndispatch_resend' => 'old', 'keep' => 1, 'n8ndispatch' => ['mode' => 'dialoghsm']];

        $new = HsmProviderOutcome::metadataAfterResend($old, $this->request(), HsmSendResult::accepted('w', 200, null), 'NEW');

        $this->assertSame(1, $new['keep']);
        $this->assertSame('sent', $new['n8ndispatch']['outcome']);
        $this->assertSame('production', $new['n8ndispatch']['status']);
        $this->assertSame('NEW', $new['n8ndispatch_resend']);
        $this->assertArrayNotHasKey('failed', $new);
        $this->assertArrayNotHasKey('reason', $new);
        $this->assertArrayNotHasKey('errors', $new);
    }

    public function testARefusedResendMarksTheLogFailedWithTheReason(): void
    {
        $new = HsmProviderOutcome::metadataAfterResend(['n8ndispatch' => ['mode' => 'dialoghsm']], $this->request(), HsmSendResult::refused('Bad number', 400), 'NEW');

        $this->assertSame(1, $new['failed']);
        $this->assertSame('Bad number', $new['reason']);
        $this->assertSame('failed', HsmProviderOutcome::outcomeOf($new));
    }
}
