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
}
