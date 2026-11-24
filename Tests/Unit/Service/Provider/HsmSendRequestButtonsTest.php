<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:dispatch-hsm')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
class HsmSendRequestButtonsTest extends TestCase
{
    /**
     * @param array<int|string, mixed> $buttons
     */
    private function request(array $buttons, array $variables = ['1' => 'Ana']): HsmSendRequest
    {
        return new HsmSendRequest(1, '+5511999990001', 'pt_BR', 't', 'text', $variables, [], 3, null, null, $buttons);
    }

    public function testButtonsAreSortedByPositionAndNormalised(): void
    {
        $r = $this->request([2 => ['type' => 'url', 'value' => 'b'], '0' => ['type' => 'copy_code', 'value' => 'a'], 1 => 'junk', 3 => ['type' => 'url']]);

        $this->assertSame([0 => ['type' => 'copy_code', 'value' => 'a'], 2 => ['type' => 'url', 'value' => 'b']], $r->buttonVariables);
    }

    public function testToArrayCarriesTheButtonsOnlyWhenThereAreSome(): void
    {
        $this->assertArrayNotHasKey('button_variables', $this->request([])->toArray());
        $this->assertSame([1 => ['type' => 'url', 'value' => 'x']], $this->request([1 => ['type' => 'url', 'value' => 'x']])->toArray()['button_variables']);
    }

    public function testFromArrayRestoresTheButtons(): void
    {
        $original = $this->request([1 => ['type' => 'url', 'value' => 'x']]);
        $restored = HsmSendRequest::fromArray($original->toArray(), null, null);

        $this->assertSame($original->buttonVariables, $restored->buttonVariables);
        $this->assertSame([], HsmSendRequest::fromArray($this->request([])->toArray(), null, null)->buttonVariables);
    }

    public function testWithVariablesKeepsTheButtons(): void
    {
        $r = $this->request([0 => ['type' => 'url', 'value' => 'x']])->withVariables(['1' => 'Bia']);

        $this->assertSame([0 => ['type' => 'url', 'value' => 'x']], $r->buttonVariables);
        $this->assertSame(['1' => 'Bia'], $r->variables);
    }

    public function testAnEmptyButtonValueIsNamedByItsHumanPosition(): void
    {
        $this->assertSame('button 2', $this->request([1 => ['type' => 'url', 'value' => '  ']])->firstEmptyVariable());
        $this->assertNull($this->request([1 => ['type' => 'url', 'value' => '0']])->firstEmptyVariable());
    }

    public function testABodyVariableIsReportedBeforeAButton(): void
    {
        $this->assertSame('1', (string) $this->request([0 => ['type' => 'url', 'value' => '']], ['1' => ''])->firstEmptyVariable());
    }
}
