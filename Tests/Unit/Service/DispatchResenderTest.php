<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\DispatchResender;
use PHPUnit\Framework\TestCase;

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

    public function testRefusalMessageIsTheNoteABlankLineAndN8nsWholeAnswer(): void
    {
        $this->assertSame(
            "Reenvio feito em 03/10/2026 23:19\nPor tester@example.com\n\n{\"error\":\"template invalido\"}",
            DispatchResender::refusalMessage("Reenvio feito em 03/10/2026 23:19\nPor tester@example.com", ' {"error":"template invalido"} ', 'HTTP 400')
        );
    }

    public function testRefusalMessageFallsBackToTheReasonWhenN8nSentNoBody(): void
    {
        $this->assertSame("nota\n\nHTTP 500", DispatchResender::refusalMessage('nota', '  ', 'HTTP 500'));
    }
}
