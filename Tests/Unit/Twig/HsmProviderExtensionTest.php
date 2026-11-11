<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Twig\HsmProviderExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class HsmProviderExtensionTest extends TestCase
{
    private DispatchListReader $reader;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(DispatchListReader::class);
    }

    private function extension(): HsmProviderExtension
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'T:'.$key);

        return new HsmProviderExtension(new HsmProviderPolicy(new NullHsmProviderGateway()), $translator, $this->reader);
    }

    public function testTheFunctionsAreRegistered(): void
    {
        $names = array_map(static fn ($function): string => $function->getName(), $this->extension()->getFunctions());

        $this->assertContains('n8ndispatch_hsm_router_label', $names);
        $this->assertContains('n8ndispatch_hsm_provider_label', $names);
        $this->assertContains('n8ndispatch_is_provider', $names);
        $this->assertContains('n8ndispatch_hsm_number_label', $names);
        $this->assertContains('n8ndispatch_provider_dispatch', $names);
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

    public function testTheTimelineReadsTheDispatchOfItsLogLikeTheDetailsModal(): void
    {
        $row = ['id' => 5, 'campaignName' => 'Cf - X'];
        $this->reader->expects($this->once())->method('find')->with(5)->willReturn($row);

        $this->assertSame($row, $this->extension()->dispatchOf(5));
    }

    public function testALogWithoutADispatchRowGivesNone(): void
    {
        $this->reader->method('find')->willReturn(null);

        $this->assertNull($this->extension()->dispatchOf(9));
    }
}
