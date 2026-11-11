<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity\Validation;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Class-level rule for the fields that depend on how the template is sent (HsmTemplate::provider).
 *
 * n8n: the router is required, the number is not read. DialogHSM: the number is required, the router is not read
 * (it may hold what the template had before), and the image link must end in jpg, jpeg or png: the DialogHSM makes its header
 * only for those, and a message without the header is refused by the Meta.
 * Only what the entity alone can know is here; whether the plugin is installed and the number still exists is
 * checked by the form, which has the gateway.
 *
 * Used as a callable array in the entity's validator metadata (not a closure: the validator caches that metadata,
 * and closures can't be serialized).
 */
final class HsmProviderFields
{
    public const PROVIDER_INVALID = 'mautic.n8ndispatch.hsmtemplate.error.provider_invalid';
    public const NUMBER_REQUIRED  = 'mautic.n8ndispatch.hsmtemplate.error.number_required';
    public const IMAGE_EXTENSION  = 'mautic.n8ndispatch.hsmtemplate.error.dialoghsm_image_extension';

    public static function validate(HsmTemplate $template, ExecutionContextInterface $context): void
    {
        match ($template->getProvider()) {
            HsmTemplate::PROVIDER_N8N      => self::validateN8n($template, $context),
            HsmTemplate::PROVIDER_DIALOGHSM => self::validateDialogHsm($template, $context),
            default                        => $context->buildViolation(self::PROVIDER_INVALID)->atPath('provider')->addViolation(),
        };
    }

    private static function validateN8n(HsmTemplate $template, ExecutionContextInterface $context): void
    {
        if ('' === trim((string) $template->getRouter())) {
            $context->buildViolation('mautic.core.value.required')->atPath('router')->addViolation();
        }
    }

    private static function validateDialogHsm(HsmTemplate $template, ExecutionContextInterface $context): void
    {
        $number = $template->getWhatsappNumberId();

        if (null === $number || $number < 1) {
            $context->buildViolation(self::NUMBER_REQUIRED)->atPath('whatsappNumberId')->addViolation();
        }

        // Whatever HsmCards found wrong with the link is its own message: only a valid https link is judged here.
        $image = $template->getCards()[0];

        if (HsmTemplate::TYPE_IMAGE === $template->getType() && '' !== $image && 1 !== preg_match('/\.(jpe?g|png)(\?.*)?$/i', $image)) {
            $context->buildViolation(self::IMAGE_EXTENSION)->atPath('cards')->addViolation();
        }
    }
}
