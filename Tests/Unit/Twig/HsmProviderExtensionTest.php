<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Twig\HsmProviderExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Group('area:dispatch-hsm')]
#[Group('risk:UI')]
#[Group('risk:CRITICO')]
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

    private function withRouter(bool $available, ?RouterInterface $router): HsmProviderExtension
    {
        $gateway = $this->createMock(HsmProviderGateway::class);
        $gateway->method('isAvailable')->willReturn($available);
        $translator = $this->createMock(TranslatorInterface::class);

        return new HsmProviderExtension(new HsmProviderPolicy($gateway), $translator, $router);
    }

    private function routerGenerating(string $url): RouterInterface
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn($url);

        return $router;
    }

    private function dialogTemplate(?int $number = 7): HsmTemplate
    {
        return (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM)->setWhatsappNumberId($number);
    }

    public function testTheTemplatesUrlOfADialogTemplateIsTheRouteWithoutTheNumberId(): void
    {
        $ext = $this->withRouter(true, $this->routerGenerating('/s/dialoghsm/numbers/987654321/templates'));

        $this->assertSame('/s/dialoghsm/numbers//templates', $ext->dialogTemplatesUrl($this->dialogTemplate()));
        $this->assertContains('n8ndispatch_dialoghsm_templates_url', array_map(static fn ($f): string => $f->getName(), $ext->getFunctions()));
    }

    public function testNoTemplatesUrlForAnN8nTemplateOrWithoutNumberOrWithoutThePlugin(): void
    {
        $router = $this->routerGenerating('/x/987654321');

        $this->assertNull($this->withRouter(true, $router)->dialogTemplatesUrl((new HsmTemplate())->setRouter('r')), 'n8n template');
        $this->assertNull($this->withRouter(true, $router)->dialogTemplatesUrl($this->dialogTemplate(null)), 'no number');
        $this->assertNull($this->withRouter(false, $router)->dialogTemplatesUrl($this->dialogTemplate()), 'plugin unavailable');
        $this->assertNull($this->withRouter(true, null)->dialogTemplatesUrl($this->dialogTemplate()), 'no router');
    }

    public function testNoTemplatesUrlWhenTheDialogRouteDoesNotExist(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willThrowException(new RouteNotFoundException());

        $this->assertNull($this->withRouter(true, $router)->dialogTemplatesUrl($this->dialogTemplate()));
    }
}
