<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Service\StatusResponseParser;
use PHPUnit\Framework\TestCase;

class StatusResponseParserTest extends TestCase
{
    private StatusResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new StatusResponseParser();
    }

    public function testParsesEmailItems(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_EMAIL, json_encode(['items' => [
            ['logSendEmailId' => 4298591, 'outcome' => 'success'],
            ['logSendEmailId' => 4298592, 'outcome' => 'error', 'message' => 'caixa cheia'],
            ['logSendEmailId' => 4298593, 'outcome' => 'pending'],
        ]]));

        $this->assertSame(0, $result['invalid']);
        $this->assertCount(3, $result['items']);
        $this->assertSame('logSendEmailId', $result['items'][0]['refType']);
        $this->assertSame('4298591', $result['items'][0]['refValue'], 'ref values are compared as strings');
        $this->assertSame('success', $result['items'][0]['outcome']);
        $this->assertNull($result['items'][0]['message']);
        $this->assertSame('caixa cheia', $result['items'][1]['message']);
    }

    public function testAcceptsTheItemsNestedUnderBodyLikeTheOtherResponses(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_SMS, json_encode([
            'body'       => ['items' => [['logSendSmsId' => 9001, 'outcome' => 'success']]],
            'statusCode' => 200,
        ]));

        $this->assertCount(1, $result['items']);
        $this->assertSame('logSendSmsId', $result['items'][0]['refType']);
    }

    public function testHsmHasTwoIndependentRefsOnePerItem(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_HSM, json_encode(['items' => [
            ['logSendHsmId' => 4298593, 'outcome' => 'success'],
            ['logSendHsmUuid' => '5c48a721-8cb1-43c7-ac46-048ce65b4233', 'outcome' => 'pending'],
        ]]));

        $this->assertCount(2, $result['items']);
        $this->assertSame('logSendHsmId', $result['items'][0]['refType']);
        $this->assertSame('logSendHsmUuid', $result['items'][1]['refType']);
        $this->assertSame('5c48a721-8cb1-43c7-ac46-048ce65b4233', $result['items'][1]['refValue']);
    }

    public function testItemWithAnInvalidOutcomeIsDroppedAndCounted(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_EMAIL, json_encode(['items' => [
            ['logSendEmailId' => 1, 'outcome' => 'entregue'],
            ['logSendEmailId' => 2, 'outcome' => 'success'],
        ]]));

        $this->assertSame(1, $result['invalid']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('2', $result['items'][0]['refValue']);
    }

    public function testItemWithoutAnIdOfItsChannelIsDroppedAndCounted(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_EMAIL, json_encode(['items' => [
            ['logSendSmsId' => 1, 'outcome' => 'success'],
            ['outcome' => 'success'],
            ['logSendEmailId' => 3, 'outcome' => 'success'],
        ]]));

        $this->assertSame(2, $result['invalid']);
        $this->assertCount(1, $result['items']);
    }

    public function testItemThatIsNotAnObjectIsDroppedAndCounted(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_EMAIL, json_encode(['items' => ['x', null, ['logSendEmailId' => 3, 'outcome' => 'success']]]));

        $this->assertSame(2, $result['invalid']);
        $this->assertCount(1, $result['items']);
    }

    public function testTheWholeItemIsKeptAsTheBody(): void
    {
        $result = $this->parser->parse(DispatchTracking::CHANNEL_EMAIL, json_encode(['items' => [
            ['logSendEmailId' => 1, 'outcome' => 'error', 'message' => 'x', 'extra' => ['a' => 1]],
        ]]));

        $this->assertSame(['logSendEmailId' => 1, 'outcome' => 'error', 'message' => 'x', 'extra' => ['a' => 1]], $result['items'][0]['body']);
    }

    /**
     * @dataProvider unusableBodies
     */
    public function testReturnsNullWhenThereIsNoItemsList(string $body): void
    {
        $this->assertNull($this->parser->parse(DispatchTracking::CHANNEL_EMAIL, $body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'empty'          => [''];
        yield 'not json'       => ['<html>502</html>'];
        yield 'json scalar'    => ['"ok"'];
        yield 'no items key'   => ['{"logSendEmailId":1}'];
        yield 'items not list' => ['{"items":"x"}'];
    }

    public function testAMessageThatIsAnObjectOrAListIsKeptAsIs(): void
    {
        $parser = new StatusResponseParser();
        $result = $parser->parse('email', json_encode([
            'items' => [
                ['logSendEmailId' => 1, 'outcome' => 'error', 'message' => ['code' => 400, 'detail' => 'x']],
                ['logSendEmailId' => 2, 'outcome' => 'error', 'message' => ['a', 'b']],
                ['logSendEmailId' => 3, 'outcome' => 'error', 'message' => []],
                ['logSendEmailId' => 4, 'outcome' => 'error', 'message' => ''],
            ],
        ]));

        $this->assertSame(['code' => 400, 'detail' => 'x'], $result['items'][0]['message']);
        $this->assertSame(['a', 'b'], $result['items'][1]['message']);
        $this->assertNull($result['items'][2]['message']);
        $this->assertNull($result['items'][3]['message']);
    }
}
