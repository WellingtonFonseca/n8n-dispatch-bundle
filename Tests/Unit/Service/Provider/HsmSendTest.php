<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendResult;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:dispatch-hsm')]
#[Group('area:dispatch-history')]
#[Group('risk:CRITICO')]
class HsmSendTest extends TestCase
{
    private function request(): HsmSendRequest
    {
        return new HsmSendRequest(
            contactId: 7,
            phone: '+5511999990001',
            language: 'pt_BR',
            template: 'welcome_v1',
            type: 'carousel',
            variables: ['2' => 'Curso X', '1' => 'Ana'],
            cards: [['card_index' => 0, 'header_image_link' => 'https://x.test/a.png'], ['card_index' => 1, 'header_image_link' => 'https://x.test/b.png']],
            numberId: 3,
            campaignId: 11,
            campaignEventId: 22,
        );
    }

    public function testTheRequestIsWhatTheLogShowsAsTheBody(): void
    {
        $this->assertSame([
            'contact_id'        => 7,
            'phone'             => '+5511999990001',
            'language'          => 'pt_BR',
            'template'          => 'welcome_v1',
            'type'              => 'carousel',
            'variables'         => ['1' => 'Ana', '2' => 'Curso X'],
            'whatsapp_number_id' => 3,
            'cards'             => [['card_index' => 0, 'header_image_link' => 'https://x.test/a.png'], ['card_index' => 1, 'header_image_link' => 'https://x.test/b.png']],
        ], $this->request()->toArray());
    }

    public function testTheVariablesAreSortedByTheirNumber(): void
    {
        $request = new HsmSendRequest(1, '+5511999990001', 'pt_BR', 't', 'text', ['10' => 'j', '2' => 'b', '1' => 'a'], [], 3, null, null);

        $this->assertSame(['1', '2', '10'], array_map('strval', array_keys($request->variables)));
    }

    public function testACardlessRequestHasNoCardsKey(): void
    {
        $request = new HsmSendRequest(1, '+5511999990001', 'pt_BR', 't', 'text', ['1' => 'a'], [], 3, null, null);

        $this->assertArrayNotHasKey('cards', $request->toArray());
    }

    public function testAnAcceptedResultKeepsTheWamidAndTheAnswer(): void
    {
        $result = HsmSendResult::accepted('wamid.X', 200, ['messages' => [['id' => 'wamid.X']]]);

        $this->assertTrue($result->accepted);
        $this->assertSame([
            'accepted'    => true,
            'wamid'       => 'wamid.X',
            'http_status' => 200,
            'error'       => null,
            'response'    => ['messages' => [['id' => 'wamid.X']]],
        ], $result->toArray());
    }

    public function testARefusedResultKeepsTheReason(): void
    {
        $result = HsmSendResult::refused('[API 360dialog] template not found', 404, ['error' => 'x']);

        $this->assertFalse($result->accepted);
        $this->assertSame([
            'accepted'    => false,
            'wamid'       => null,
            'http_status' => 404,
            'error'       => '[API 360dialog] template not found',
            'response'    => ['error' => 'x'],
        ], $result->toArray());
    }

    public function testTheNullGatewayRefusesEverySendAndSaysWhy(): void
    {
        $result = (new NullHsmProviderGateway())->send($this->request());

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('DialogHSM', (string) $result->error);
    }

    public function testTheRequestIsRebuiltFromWhatTheLogKept(): void
    {
        $again = HsmSendRequest::fromArray($this->request()->toArray(), 11, 22);

        $this->assertEquals($this->request(), $again);
    }

    public function testALogRequestMissingAKeyIsNotRebuilt(): void
    {
        foreach (['contact_id', 'phone', 'language', 'template', 'type', 'whatsapp_number_id'] as $key) {
            $data = $this->request()->toArray();
            unset($data[$key]);

            $this->assertNull(HsmSendRequest::fromArray($data, null, null), $key);
        }
    }

    public function testARebuiltRequestWithoutVariablesOrCardsHasNone(): void
    {
        $data = $this->request()->toArray();
        unset($data['variables'], $data['cards']);

        $request = HsmSendRequest::fromArray($data, null, null);

        $this->assertSame([], $request->variables);
        $this->assertSame([], $request->cards);
    }

    public function testARebuiltRequestKeepsTextValuesOnlyAndTheCardsAsAList(): void
    {
        $data              = $this->request()->toArray();
        $data['variables'] = ['1' => 7, '2' => ['nested'], '3' => 'c'];
        $data['cards']     = [5 => ['card_index' => 0, 'header_image_link' => 'https://x.test/a.png']];

        $request = HsmSendRequest::fromArray($data, null, null);

        $this->assertSame(['1' => '7', '3' => 'c'], $request->variables);
        $this->assertSame([0], array_keys($request->cards));
    }

    public function testNewVariablesReplaceTheOldAndTheRestStays(): void
    {
        $changed = $this->request()->withVariables(['1' => 'Zeca']);

        $this->assertSame(['1' => 'Zeca'], $changed->variables);
        $this->assertSame(['contact_id' => 7, 'phone' => '+5511999990001', 'campaign' => 11, 'event' => 22, 'number' => 3], ['contact_id' => $changed->contactId, 'phone' => $changed->phone, 'campaign' => $changed->campaignId, 'event' => $changed->campaignEventId, 'number' => $changed->numberId]);
        $this->assertSame($this->request()->cards, $changed->cards);
    }

    public function testTheFirstEmptyVariableIsFoundAndAZeroIsAValue(): void
    {
        $make = static fn (array $variables): HsmSendRequest => new HsmSendRequest(1, '+55', 'pt_BR', 't', 'text', $variables, [], 3, null, null);

        $this->assertSame(2, $make(['1' => 'a', '2' => ' ', '3' => ''])->firstEmptyVariable());
        $this->assertNull($make(['1' => '0'])->firstEmptyVariable());
        $this->assertNull($make([])->firstEmptyVariable());
    }
}
