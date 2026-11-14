<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendResult;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:dispatch-hsm')]
#[Group('area:dispatch-history')]
#[Group('risk:CRITICO')]
#[Group('risk:FLUXO')]
class HsmProviderPolicyTest extends TestCase
{
    /**
     * @param array<int, string> $numbers
     */
    private function policy(bool $available, array $numbers = []): HsmProviderPolicy
    {
        return new HsmProviderPolicy(new class($available, $numbers) implements HsmProviderGateway {
            /**
             * @param array<int, string> $numbers
             */
            public function __construct(private bool $available, private array $numbers)
            {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function numbers(): array
            {
                return $this->available ? $this->numbers : [];
            }

            public function numberName(int $id): ?string
            {
                return $this->available ? ($this->numbers[$id] ?? null) : null;
            }

            public function send(HsmSendRequest $request): HsmSendResult
            {
                return HsmSendResult::refused('not used by the policy');
            }
        });
    }

    public function testWithoutTheOtherPluginOnlyN8nIsOffered(): void
    {
        $policy = new HsmProviderPolicy(new NullHsmProviderGateway());

        $this->assertSame(
            ['mautic.n8ndispatch.hsmtemplate.provider.n8n' => HsmTemplate::PROVIDER_N8N],
            $policy->providerChoices(HsmTemplate::PROVIDER_N8N)
        );
    }

    public function testWithTheOtherPluginBothAreOffered(): void
    {
        $this->assertSame(
            [
                'mautic.n8ndispatch.hsmtemplate.provider.n8n'       => HsmTemplate::PROVIDER_N8N,
                'mautic.n8ndispatch.hsmtemplate.provider.dialoghsm' => HsmTemplate::PROVIDER_DIALOGHSM,
            ],
            $this->policy(true)->providerChoices(HsmTemplate::PROVIDER_N8N)
        );
    }

    public function testATemplateAlreadyOnTheDialogHsmKeepsItsChoiceWhenThePluginIsGone(): void
    {
        // Otherwise the edit form of that template would refuse its own saved value as an invalid choice.
        $choices = (new HsmProviderPolicy(new NullHsmProviderGateway()))->providerChoices(HsmTemplate::PROVIDER_DIALOGHSM);

        $this->assertContains(HsmTemplate::PROVIDER_DIALOGHSM, $choices);
    }

    public function testTheNumbersAreListedByName(): void
    {
        $this->assertSame(
            ['ESPM A' => 3, 'ESPM B' => 5],
            $this->policy(true, [3 => 'ESPM A', 5 => 'ESPM B'])->numberChoices(null, '#%id% missing')
        );
    }

    public function testASavedNumberThatNoLongerExistsStaysInTheListWithAWarningLabel(): void
    {
        $this->assertSame(
            ['ESPM A' => 3, '#9 missing' => 9],
            $this->policy(true, [3 => 'ESPM A'])->numberChoices(9, '#%id% missing')
        );
    }

    public function testASavedNumberThatExistsIsNotListedTwice(): void
    {
        $this->assertSame(['ESPM A' => 3], $this->policy(true, [3 => 'ESPM A'])->numberChoices(3, '#%id% missing'));
    }

    public function testWithoutTheOtherPluginTheListIsEmptyEvenForASavedNumber(): void
    {
        $this->assertSame(['#4 missing' => 4], (new HsmProviderPolicy(new NullHsmProviderGateway()))->numberChoices(4, '#%id% missing'));
        $this->assertSame([], (new HsmProviderPolicy(new NullHsmProviderGateway()))->numberChoices(null, '#%id% missing'));
    }

    public function testN8nIsAlwaysAccepted(): void
    {
        $this->assertNull($this->policy(false)->problem(HsmTemplate::PROVIDER_N8N, null));
        $this->assertNull($this->policy(false)->problem(HsmTemplate::PROVIDER_N8N, 99));
    }

    public function testTheDialogHsmWithoutThePluginIsRefusedOnTheProviderField(): void
    {
        $this->assertSame(
            [HsmProviderPolicy::FIELD_PROVIDER, HsmProviderPolicy::UNAVAILABLE],
            $this->policy(false)->problem(HsmTemplate::PROVIDER_DIALOGHSM, 3)
        );
    }

    public function testAMissingNumberIsRefusedOnTheNumberField(): void
    {
        $this->assertSame(
            [HsmProviderPolicy::FIELD_NUMBER, HsmProviderPolicy::NUMBER_MISSING],
            $this->policy(true, [3 => 'ESPM A'])->problem(HsmTemplate::PROVIDER_DIALOGHSM, 9)
        );
    }

    public function testTheRouterColumnShowsTheRouterForN8n(): void
    {
        $template = (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_N8N)->setRouter('blip-a')->setWhatsappNumberId(3);

        $this->assertSame('blip-a', $this->policy(true, [3 => 'ESPM A'])->routerLabel($template, '#%id% missing'));
    }

    public function testTheRouterColumnShowsTheNumberNameForTheDialogHsm(): void
    {
        $template = (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM)->setRouter('old')->setWhatsappNumberId(3);

        $this->assertSame('ESPM A', $this->policy(true, [3 => 'ESPM A'])->routerLabel($template, '#%id% missing'));
    }

    public function testTheRouterColumnWarnsWhenTheNumberIsGoneOrThePluginIsNot(): void
    {
        $template = (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM)->setWhatsappNumberId(9);

        $this->assertSame('#9 missing', $this->policy(true, [3 => 'ESPM A'])->routerLabel($template, '#%id% missing'));
        $this->assertSame('#9 missing', (new HsmProviderPolicy(new NullHsmProviderGateway()))->routerLabel($template, '#%id% missing'));
    }

    public function testTheRouterColumnIsEmptyForTheDialogHsmWithoutANumber(): void
    {
        $template = (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM);

        $this->assertSame('', $this->policy(true, [3 => 'ESPM A'])->routerLabel($template, '#%id% missing'));
    }

    public function testTheNumberLabelIsTheNameOrTheWarningWithTheId(): void
    {
        $policy = $this->policy(true, [3 => 'ESPM A']);

        $this->assertSame('ESPM A', $policy->numberLabel(3, '#%id% missing'));
        $this->assertSame('#9 missing', $policy->numberLabel(9, '#%id% missing'));
        $this->assertSame('#3 missing', (new HsmProviderPolicy(new NullHsmProviderGateway()))->numberLabel(3, '#%id% missing'));
    }

    public function testNumberOneIsCheckedLikeAnyOther(): void
    {
        $this->assertSame(
            [HsmProviderPolicy::FIELD_NUMBER, HsmProviderPolicy::NUMBER_MISSING],
            $this->policy(true, [3 => 'ESPM A'])->problem(HsmTemplate::PROVIDER_DIALOGHSM, 1)
        );
    }

    public function testAnExistingNumberIsAccepted(): void
    {
        $this->assertNull($this->policy(true, [3 => 'ESPM A'])->problem(HsmProviderPolicyTest::dialog(), 3));
    }

    public function testNoNumberIsLeftToTheRequiredRuleOfTheEntity(): void
    {
        // HsmProviderFields says "pick the number"; two messages for one empty field would be noise.
        $this->assertNull($this->policy(true, [3 => 'ESPM A'])->problem(HsmTemplate::PROVIDER_DIALOGHSM, null));
        $this->assertNull($this->policy(true, [3 => 'ESPM A'])->problem(HsmTemplate::PROVIDER_DIALOGHSM, 0));
    }

    public static function dialog(): string
    {
        return HsmTemplate::PROVIDER_DIALOGHSM;
    }
}
