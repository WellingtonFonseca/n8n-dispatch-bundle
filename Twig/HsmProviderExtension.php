<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderOutcome;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the screens show of how an HSM template is sent: the provider and router columns of the template list (the router is the
 * name of the number for the DialogHSM), and the card of a dispatch handed to another plugin. The rules are in
 * Service/Provider/HsmProviderPolicy.php and HsmProviderOutcome.php.
 */
class HsmProviderExtension extends AbstractExtension
{
    public function __construct(
        private readonly HsmProviderPolicy $policy,
        private readonly TranslatorInterface $translator,
        private readonly ?RouterInterface $router = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('n8ndispatch_hsm_provider_label', [$this, 'providerLabel']),
            new TwigFunction('n8ndispatch_hsm_router_label', [$this, 'routerLabel']),
            new TwigFunction('n8ndispatch_hsm_number_label', [$this, 'numberLabel']),
            new TwigFunction('n8ndispatch_dialoghsm_templates_url', [$this, 'dialogTemplatesUrl']),
            new TwigFunction('n8ndispatch_is_provider', [$this, 'isProvider']),
        ];
    }

    public function providerLabel(HsmTemplate $template): string
    {
        return $this->translator->trans('mautic.n8ndispatch.hsmtemplate.provider.'.$template->getProvider());
    }

    /**
     * The DialogHSM's template list URL (the number id is appended by the script), for a DialogHSM template whose plugin is there; null
     * otherwise. Same URL as HsmTemplateType::finishView, read by the details modal of the list.
     */
    public function dialogTemplatesUrl(HsmTemplate $template): ?string
    {
        if (null === $this->router || HsmTemplate::PROVIDER_DIALOGHSM !== $template->getProvider() || !$template->getWhatsappNumberId() || !$this->policy->isAvailable()) {
            return null;
        }

        try {
            return str_replace('987654321', '', $this->router->generate('mautic_dialoghsm_number_templates', ['objectId' => 987654321]));
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    public function routerLabel(HsmTemplate $template): string
    {
        return $this->policy->routerLabel($template, $this->translator->trans('mautic.n8ndispatch.hsmtemplate.number.missing'));
    }

    public function numberLabel(int $id): string
    {
        return $this->policy->numberLabel($id, $this->translator->trans('mautic.n8ndispatch.hsmtemplate.number.missing'));
    }

    /**
     * Whether the log of a step is a dispatch handed to another plugin: its card is not the n8n one.
     */
    public function isProvider(mixed $metadata): bool
    {
        return is_array($metadata) && HsmProviderOutcome::isProviderDispatch($metadata);
    }
}
