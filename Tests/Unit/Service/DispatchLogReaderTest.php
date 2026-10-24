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

    public function testDispatchOfAnAcceptedProductionCallKeepsTheResponseAndTheIds(): void
    {
        $d = $this->reader->dispatchOf(DispatchTracking::CHANNEL_HSM, [
            'logSendHsmId' => 4307097, 'logSendHsmUuid' => 'u',
            'n8ndispatch'  => ['status' => 'production', 'httpStatusCode' => 200, 'response' => ['logSendHsmId' => 4307097, 'uuid' => 'u']],
        ]);

        $this->assertSame('production', $d['mode']);
        $this->assertTrue($d['accepted']);
        $this->assertSame(['logSendHsmId' => 4307097, 'uuid' => 'u'], $d['response']);
        $this->assertSame(['logSendHsmId' => 4307097, 'logSendHsmUuid' => 'u'], $d['refs']);
    }

    public function testDispatchOfARefusedProductionCallKeepsTheResponseAndNoIds(): void
    {
        $d = $this->reader->dispatchOf(DispatchTracking::CHANNEL_EMAIL, [
            'logSendEmailId' => 1,
            'n8ndispatch'    => ['status' => 'production', 'httpStatusCode' => 404, 'response' => ['body' => ['error' => 'x']]],
        ]);

        $this->assertFalse($d['accepted']);
        $this->assertSame(['body' => ['error' => 'x']], $d['response']);
        $this->assertSame([], $d['refs']);
    }

    public function testDispatchOfATextResponseOrNoneIsKeptAsIsOrNull(): void
    {
        $text = $this->reader->dispatchOf(DispatchTracking::CHANNEL_SMS, ['n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 502, 'response' => 'Bad gateway']]);
        $none = $this->reader->dispatchOf(DispatchTracking::CHANNEL_SMS, ['n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200, 'response' => '']]);

        $this->assertSame('Bad gateway', $text['response']);
        $this->assertNull($none['response']);
    }

    public function testDispatchOfATestOrPausedStepIsSimulatedWithNothingSent(): void
    {
        foreach (['test', 'paused'] as $mode) {
            $d = $this->reader->dispatchOf(DispatchTracking::CHANNEL_EMAIL, ['logSendEmailId' => 1, 'n8ndispatch' => ['status' => $mode]]);

            $this->assertSame($mode, $d['mode']);
            $this->assertNull($d['response']);
            $this->assertSame([], $d['refs']);
        }
    }

    public function testDispatchOfALogThatIsNotADispatchIsNull(): void
    {
        $this->assertNull($this->reader->dispatchOf(DispatchTracking::CHANNEL_EMAIL, []));
        $this->assertNull($this->reader->dispatchOf(DispatchTracking::CHANNEL_EMAIL, ['errors' => ['cancelled'], 'n8ndispatch_cancellation' => ['x' => 1]]));
        $this->assertNull($this->reader->dispatchOf(DispatchTracking::CHANNEL_EMAIL, ['n8ndispatch' => ['status' => 'weird']]));
    }

    public function testAnHttpStatusOf300OrMoreHasNoIdsToTrack(): void
    {
        $meta = fn ($status): array => ['logSendEmailId' => 5, 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => $status]];

        $this->assertSame([DispatchTracking::REF_EMAIL => 5], $this->reader->refs('email', $meta(299)));
        $this->assertSame([], $this->reader->refs('email', $meta(300)));
        $this->assertSame([], $this->reader->refs('email', $meta('300')));
        $this->assertSame([DispatchTracking::REF_EMAIL => 5], $this->reader->refs('email', $meta('299')));
    }

    public function testTheLogsOwnIdBeatsTheResponseAndTheResponseBeatsItsBody(): void
    {
        $block = static fn (array $response): array => ['status' => 'production', 'response' => $response];
        $cases = [
            'email' => ['logSendEmailId', 'logSendEmailId', DispatchTracking::REF_EMAIL],
            'sms'   => ['logSendSmsId', 'logSendSmsId', DispatchTracking::REF_SMS],
            'hsm'   => ['logSendHsmId', 'logSendHsmId', DispatchTracking::REF_HSM_ID],
        ];

        foreach ($cases as $channel => [$metaKey, $bodyKey, $ref]) {
            $all = $this->reader->refs($channel, [$metaKey => 'meta', 'n8ndispatch' => $block([$bodyKey => 'response', 'body' => [$bodyKey => 'nested']])]);
            $noMeta = $this->reader->refs($channel, ['n8ndispatch' => $block([$bodyKey => 'response', 'body' => [$bodyKey => 'nested']])]);
            $onlyNested = $this->reader->refs($channel, ['n8ndispatch' => $block(['body' => [$bodyKey => 'nested']])]);

            $this->assertSame('meta', $all[$ref], $channel);
            $this->assertSame('response', $noMeta[$ref], $channel);
            $this->assertSame('nested', $onlyNested[$ref], $channel);
        }

        $uuid = fn (array $meta, array $response): array => $this->reader->refs('hsm', $meta + ['n8ndispatch' => $block($response)]);
        $this->assertSame('m', $uuid(['logSendHsmUuid' => 'm'], ['uuid' => 'r', 'body' => ['uuid' => 'n']])[DispatchTracking::REF_HSM_UUID]);
        $this->assertSame('r', $uuid([], ['uuid' => 'r', 'body' => ['uuid' => 'n']])[DispatchTracking::REF_HSM_UUID]);
        $this->assertSame('n', $uuid([], ['body' => ['uuid' => 'n']])[DispatchTracking::REF_HSM_UUID]);
    }

    public function testAnUnknownChannelHasNoIdsAndOnlyTextOrNumbersCount(): void
    {
        $meta = ['n8ndispatch' => ['status' => 'production'], 'logSendEmailId' => 5];

        $this->assertSame([], $this->reader->refs('fax', $meta));
        $this->assertSame([], $this->reader->refs('email', ['logSendEmailId' => '', 'n8ndispatch' => ['status' => 'production']]));
        $this->assertSame([], $this->reader->refs('email', ['logSendEmailId' => ['5'], 'n8ndispatch' => ['status' => 'production']]));
        $this->assertSame([], $this->reader->refs('email', ['logSendEmailId' => 5.5, 'n8ndispatch' => ['status' => 'production']]));
        $this->assertSame([DispatchTracking::REF_EMAIL => 'abc'], $this->reader->refs('email', ['logSendEmailId' => 'abc', 'n8ndispatch' => ['status' => 'production']]));
    }

    public function testADispatchOfATestOrPausedStepWasAcceptedAndTracksNothing(): void
    {
        foreach (['test', 'paused'] as $mode) {
            $this->assertSame(['mode' => $mode, 'accepted' => true, 'response' => null, 'refs' => []], $this->reader->dispatchOf('email', ['n8ndispatch' => ['status' => $mode]]));
        }
    }

    public function testAProductionDispatchIsAcceptedBelow300AndKeepsATextOrArrayResponse(): void
    {
        $dispatch = fn (array $n8n) => $this->reader->dispatchOf('email', ['n8ndispatch' => ['status' => 'production'] + $n8n]);

        $this->assertTrue($dispatch(['httpStatusCode' => 299])['accepted']);
        $this->assertTrue($dispatch(['httpStatusCode' => '299'])['accepted']);
        $this->assertFalse($dispatch(['httpStatusCode' => 300])['accepted']);
        $this->assertFalse($dispatch(['httpStatusCode' => '300'])['accepted']);
        $this->assertTrue($dispatch([])['accepted']);
        $this->assertSame(['a' => 1], $dispatch(['response' => ['a' => 1]])['response']);
        $this->assertSame('plain', $dispatch(['response' => 'plain'])['response']);
        $this->assertNull($dispatch(['response' => "  \n"])['response']);
        $this->assertNull($dispatch(['response' => 5])['response']);
    }
}
