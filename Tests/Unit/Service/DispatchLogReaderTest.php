<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Service\DispatchLogReader;
use PHPUnit\Framework\TestCase;

class DispatchLogReaderTest extends TestCase
{
    private DispatchLogReader $reader;

    protected function setUp(): void
    {
        $this->reader = new DispatchLogReader();
    }

    public function testChannelOfAnEventType(): void
    {
        $this->assertSame('email', DispatchLogReader::channelOf('n8ndispatch.email.send'));
        $this->assertSame('sms', DispatchLogReader::channelOf('n8ndispatch.sms.send'));
        $this->assertSame('hsm', DispatchLogReader::channelOf('n8ndispatch.hsm.send'));
        $this->assertNull(DispatchLogReader::channelOf('email.send'));
    }

    public function testEmailRef(): void
    {
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_EMAIL, [
            'logSendEmailId' => 6334025,
            'n8ndispatch'    => ['status' => 'production', 'httpStatusCode' => 200],
        ]);

        $this->assertSame(['logSendEmailId' => 6334025], $refs);
    }

    public function testSmsRef(): void
    {
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_SMS, [
            'logSendSmsId' => 9001,
            'n8ndispatch'  => ['status' => 'production', 'httpStatusCode' => 200],
        ]);

        $this->assertSame(['logSendSmsId' => 9001], $refs);
    }

    public function testHsmTakesBothIdsFromTheMetadata(): void
    {
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_HSM, [
            'logSendHsmId'   => 4298591,
            'logSendHsmUuid' => 'abc',
            'n8ndispatch'    => ['status' => 'production', 'httpStatusCode' => 200],
        ]);

        $this->assertSame(['logSendHsmId' => 4298591, 'logSendHsmUuid' => 'abc'], $refs);
    }

    public function testHsmUuidOfAnOldDispatchComesFromTheStoredResponse(): void
    {
        // Before the status poll only logSendHsmId was kept apart; the whole response is in 'n8ndispatch'.
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_HSM, [
            'logSendHsmId' => 4298591,
            'n8ndispatch'  => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['uuid' => 'flat-uuid', 'logSendHsmId' => 4298591]],
        ]);

        $this->assertSame(['logSendHsmId' => 4298591, 'logSendHsmUuid' => 'flat-uuid'], $refs);
    }

    public function testHsmUuidAlsoFromTheNestedResponse(): void
    {
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_HSM, [
            'logSendHsmId' => 7,
            'n8ndispatch'  => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['body' => ['uuid' => 'nested-uuid', 'logSendHsmId' => 7], 'statusCode' => 200]],
        ]);

        $this->assertSame(['logSendHsmId' => 7, 'logSendHsmUuid' => 'nested-uuid'], $refs);
    }

    public function testHsmIdIsAlsoReadFromTheResponseWhenMissingFromTheMetadata(): void
    {
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_HSM, [
            'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['body' => ['uuid' => 'u', 'logSendHsmId' => 9]]],
        ]);

        $this->assertSame(['logSendHsmId' => 9, 'logSendHsmUuid' => 'u'], $refs);
    }

    public function testHsmWithOnlyOneIdKeepsThatOne(): void
    {
        $refs = $this->reader->refs(DispatchTracking::CHANNEL_HSM, [
            'logSendHsmId' => 5,
            'n8ndispatch'  => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['logSendHsmId' => 5]],
        ]);

        $this->assertSame(['logSendHsmId' => 5], $refs);
    }

    /**
     * @dataProvider notRealDispatches
     *
     * @param array<string, mixed> $metadata
     */
    public function testNothingIsReadFromADispatchThatWasNotAcceptedByN8n(array $metadata): void
    {
        $this->assertSame([], $this->reader->refs(DispatchTracking::CHANNEL_EMAIL, $metadata));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notRealDispatches(): iterable
    {
        yield 'test mode'      => [['logSendEmailId' => 1, 'n8ndispatch' => ['status' => 'test']]];
        yield 'paused'         => [['logSendEmailId' => 1, 'n8ndispatch' => ['status' => 'paused']]];
        yield 'http error'     => [['logSendEmailId' => 1, 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 500]]];
        yield 'no id'          => [['n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]]];
        yield 'empty id'       => [['logSendEmailId' => '', 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]]];
        yield 'no n8ndispatch' => [['logSendEmailId' => 1]];
        yield 'cancelled row'  => [['errors' => ['cancelled'], 'n8ndispatch_cancellation' => ['cancelledByEmail' => 'x']]];
        yield 'empty'          => [[]];
    }

    public function testAnOldRowWithoutAnHttpStatusIsStillAccepted(): void
    {
        $this->assertSame(
            ['logSendEmailId' => 1],
            $this->reader->refs(DispatchTracking::CHANNEL_EMAIL, ['logSendEmailId' => 1, 'n8ndispatch' => ['status' => 'production']])
        );
    }
}
