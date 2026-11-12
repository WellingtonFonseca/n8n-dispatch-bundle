<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Twig\HsmProviderExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class HsmProviderExtensionTest extends TestCase
{
    private function extension(): HsmProviderExtension
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'T:'.$key);

        return new HsmProviderExtension(new HsmProviderPolicy(new NullHsmProviderGateway()), $translator);
    }

    public function testTheFunctionsAreRegistered(): void
    {
        $names = array_map(static fn ($function): string => $function->getName(), $this->extension()->getFunctions());

        $this->assertContains('n8ndispatch_hsm_router_label', $names);
        $this->assertContains('n8ndispatch_hsm_provider_label', $names);
        $this->assertContains('n8ndispatch_is_provider', $names);
        $this->assertContains('n8ndispatch_hsm_number_label', $names);
    }

    public function testTheRouterLabelOfAnN8nTemplateIsItsRouter(): void
    {
        $template = (new HsmTemplate())->setRouter('blip-a');

        $this->assertSame('blip-a', $this->extension()->routerLabel($template));
    }

    public function testTheMissingNumberLabelIsTranslatedAndKeepsTheId(): void
    {
        $template = (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM)->setWhatsappNumberId(9);

        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.number.missing', $this->extension()->routerLabel($template));
    }

    public function testTheProviderLabelIsTheTranslatedNameOfTheProvider(): void
    {
        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.provider.n8n', $this->extension()->providerLabel(new HsmTemplate()));
        $this->assertSame(
            'T:mautic.n8ndispatch.hsmtemplate.provider.dialoghsm',
            $this->extension()->providerLabel((new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM))
        );
    }

    public function testItTellsAProviderCardByTheMetadataOfTheLog(): void
    {
        $this->assertTrue($this->extension()->isProvider(['n8ndispatch' => ['mode' => 'dialoghsm']]));
        $this->assertFalse($this->extension()->isProvider(['n8ndispatch' => ['status' => 'production']]));
        $this->assertFalse($this->extension()->isProvider('not an array'));
    }

    public function testTheNumberLabelOfAMissingNumberIsTheTranslatedWarning(): void
    {
        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.number.missing', $this->extension()->numberLabel(9));
    }
}
