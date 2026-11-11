<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;

/**
 * What the HSM template form offers and refuses about the send provider, from what the gateway says about the other
 * plugin. Kept out of the form type so it is tested without building a form.
 *
 * The entity (Entity/Validation/HsmProviderFields) checks what it knows alone; this checks what needs the other
 * plugin: is it there, does the picked number still exist.
 */
class HsmProviderPolicy
{
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_NUMBER   = 'whatsappNumberId';

    public const UNAVAILABLE    = 'mautic.n8ndispatch.hsmtemplate.error.dialoghsm_unavailable';
    public const NUMBER_MISSING = 'mautic.n8ndispatch.hsmtemplate.error.number_missing';

    public function __construct(private readonly HsmProviderGateway $gateway)
    {
    }

    /**
     * Label key => value for the provider select. The DialogHSM is offered when the other plugin is available, and
     * also to a template already saved on it: without its own value in the list, the edit form would refuse it.
     *
     * @return array<string, string>
     */
    public function providerChoices(string $current): array
    {
        $choices = ['mautic.n8ndispatch.hsmtemplate.provider.n8n' => HsmTemplate::PROVIDER_N8N];

        if ($this->gateway->isAvailable() || HsmTemplate::PROVIDER_DIALOGHSM === $current) {
            $choices['mautic.n8ndispatch.hsmtemplate.provider.dialoghsm'] = HsmTemplate::PROVIDER_DIALOGHSM;
        }

        return $choices;
    }

    /**
     * Name => id for the number select. A saved number that is no longer there (deleted in the other plugin) stays
     * in the list under a warning label, so the form can show it and refuse the save with a clear message.
     *
     * @param string $missingLabel the label of that case, with %id% for the number
     *
     * @return array<string, int>
     */
    public function numberChoices(?int $current, string $missingLabel): array
    {
        $choices = array_flip($this->gateway->numbers());

        if (null !== $current && !in_array($current, $choices, true)) {
            $choices[str_replace('%id%', (string) $current, $missingLabel)] = $current;
        }

        return $choices;
    }

    /**
     * What the router column of the template list shows: the router for a template sent through n8n, the name of the
     * picked number for one sent through the DialogHSM (it has no router; the number takes its place).
     *
     * @param string $missingLabel the label of a number that no longer exists, with %id% for the number
     */
    public function routerLabel(HsmTemplate $template, string $missingLabel): string
    {
        if (!$template->usesDialogHsm()) {
            return (string) $template->getRouter();
        }

        $number = $template->getWhatsappNumberId();

        if (null === $number) {
            return '';
        }

        return $this->gateway->numberName($number) ?? str_replace('%id%', (string) $number, $missingLabel);
    }

    /**
     * The first thing the other plugin says is wrong, as [field, message key], or null. A missing number is left to
     * the entity's own required rule.
     *
     * @return array{0: string, 1: string}|null
     */
    public function problem(string $provider, ?int $number): ?array
    {
        if (HsmTemplate::PROVIDER_DIALOGHSM !== $provider) {
            return null;
        }

        if (!$this->gateway->isAvailable()) {
            return [self::FIELD_PROVIDER, self::UNAVAILABLE];
        }

        if (null === $number || $number < 1) {
            return null;
        }

        return null === $this->gateway->numberName($number) ? [self::FIELD_NUMBER, self::NUMBER_MISSING] : null;
    }
}
