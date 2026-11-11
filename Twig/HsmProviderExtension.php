<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The two provider columns of the HSM template list: how the template is sent, and its router (or the name of its
 * number for the DialogHSM). The rule is in Service/Provider/HsmProviderPolicy.php.
 */
class HsmProviderExtension extends AbstractExtension
{
    public function __construct(
        private readonly HsmProviderPolicy $policy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('n8ndispatch_hsm_provider_label', [$this, 'providerLabel']),
            new TwigFunction('n8ndispatch_hsm_router_label', [$this, 'routerLabel']),
        ];
    }

    public function providerLabel(HsmTemplate $template): string
    {
        return $this->translator->trans('mautic.n8ndispatch.hsmtemplate.provider.'.$template->getProvider());
    }

    public function routerLabel(HsmTemplate $template): string
    {
        return $this->policy->routerLabel($template, $this->translator->trans('mautic.n8ndispatch.hsmtemplate.number.missing'));
    }
}
