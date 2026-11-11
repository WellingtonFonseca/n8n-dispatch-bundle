<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Audit;

use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Says a field of an SMS / HSM template and its old and new value in the language of the person reading the history, not as the
 * database stores them: "Enviar por" instead of `provider`, "360Dialog" instead of `dialoghsm`, the name of a number instead of its
 * id. What is not known is shown as stored, so a new field of the entity still appears in the history.
 */
class AuditFieldFormatter
{
    /** field => translation key of its label (the same texts as the edit page and the details modal). */
    private const LABELS = [
        'name'               => 'mautic.core.name',
        'language'           => 'mautic.core.language',
        'description'        => 'mautic.core.description',
        'isPublished'        => 'mautic.core.form.available',
        'router'             => 'mautic.n8ndispatch.campaign.event.hsm.router',
        'hsmTemplate'        => 'mautic.n8ndispatch.campaign.event.hsm.template',
        'type'               => 'mautic.n8ndispatch.campaign.event.hsm.type',
        'cards'              => 'mautic.n8ndispatch.hsmtemplate.cards.label',
        'whatsappNumberId'   => 'mautic.n8ndispatch.timeline.provider.number',
        'variablesJson'      => 'mautic.n8ndispatch.template.details.variables',
    ];

    /** audit_log object => [field => translation key], for the fields whose text depends on the template kind. */
    private const KIND_LABELS = [
        TemplateAuditLog::HSM_TEMPLATE => ['provider' => 'mautic.n8ndispatch.hsmtemplate.provider.label', 'text' => 'mautic.n8ndispatch.campaign.event.hsm.text'],
        TemplateAuditLog::SMS_TEMPLATE => ['provider' => 'mautic.n8ndispatch.smstemplate.provider.label', 'text' => 'mautic.n8ndispatch.template.details.text'],
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly HsmProviderPolicy $policy,
    ) {
    }

    public function label(string $object, string $field): string
    {
        $key = self::KIND_LABELS[$object][$field] ?? self::LABELS[$field] ?? null;

        return null === $key ? $field : $this->translator->trans($key);
    }

    public function value(string $object, string $field, string $stored): string
    {
        return match (true) {
            '' === $stored                                           => '',
            'isPublished' === $field                                 => $this->translator->trans('1' === $stored ? 'mautic.n8ndispatch.template.details.active' : 'mautic.n8ndispatch.template.details.inactive'),
            'provider' === $field                                    => $this->translator->trans('mautic.n8ndispatch.hsmtemplate.provider.'.$stored),
            'type' === $field && TemplateAuditLog::HSM_TEMPLATE === $object => $this->translator->trans('mautic.n8ndispatch.hsmtemplate.type.'.$stored),
            'whatsappNumberId' === $field && ctype_digit($stored)   => $this->policy->numberLabel((int) $stored, $this->translator->trans('mautic.n8ndispatch.hsmtemplate.number.missing')),
            default                                                  => $stored,
        };
    }
}
