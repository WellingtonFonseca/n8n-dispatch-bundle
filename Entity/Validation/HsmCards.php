<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity\Validation;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Class-level rule for the image URLs of the HSM types that send them, ignored for every other type.
 *
 * "Imagem Carrossel": at least two image URLs, filled from the first slot on with no hole between them
 * (card_index is the slot's position, so a card 4 without a card 3 would send 0, 1, 3), each one a https URL.
 * "Imagem": the first slot alone, a https URL; the other slots may hold what a carousel left and are not read.
 *
 * Used as a callable array in the entity's validator metadata (not a closure: the validator caches that metadata,
 * and closures can't be serialized).
 */
final class HsmCards
{
    public const MIN_CARDS = 2;

    public const TOO_FEW = 'mautic.n8ndispatch.hsmtemplate.error.cards_min';
    public const GAP     = 'mautic.n8ndispatch.hsmtemplate.error.cards_gap';
    public const BAD_URL = 'mautic.n8ndispatch.hsmtemplate.error.cards_url';

    public const IMAGE_REQUIRED = 'mautic.n8ndispatch.hsmtemplate.error.image_required';
    public const IMAGE_BAD_URL  = 'mautic.n8ndispatch.hsmtemplate.error.image_url';

    public static function validate(HsmTemplate $template, ExecutionContextInterface $context): void
    {
        match ($template->getType()) {
            HsmTemplate::TYPE_IMAGE    => self::validateImage($template->getCards()[0], $context),
            HsmTemplate::TYPE_CAROUSEL => self::validateCarousel($template->getCards(), $context),
            default                    => null,
        };
    }

    /**
     * @param list<string> $cards
     */
    private static function validateCarousel(array $cards, ExecutionContextInterface $context): void
    {
        $filled  = 0;
        $blankAt = null;

        foreach ($cards as $index => $url) {
            if ('' === $url) {
                $blankAt ??= $index + 1;

                continue;
            }

            if (null !== $blankAt) {
                $context->buildViolation(self::GAP)
                    ->setParameter('%blank%', (string) $blankAt)
                    ->setParameter('%filled%', (string) ($index + 1))
                    ->atPath('cards')
                    ->addViolation();

                return;
            }

            if (!self::isHttpsUrl($url)) {
                $context->buildViolation(self::BAD_URL)
                    ->setParameter('%card%', (string) ($index + 1))
                    ->atPath('cards')
                    ->addViolation();

                return;
            }

            ++$filled;
        }

        if ($filled < self::MIN_CARDS) {
            $context->buildViolation(self::TOO_FEW)
                ->setParameter('%min%', (string) self::MIN_CARDS)
                ->atPath('cards')
                ->addViolation();
        }
    }

    private static function validateImage(string $url, ExecutionContextInterface $context): void
    {
        if ('' === $url) {
            $context->buildViolation(self::IMAGE_REQUIRED)->atPath('cards')->addViolation();

            return;
        }

        if (!self::isHttpsUrl($url)) {
            $context->buildViolation(self::IMAGE_BAD_URL)->atPath('cards')->addViolation();
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        return 1 === preg_match('#^https://\S+$#i', $url) && false !== filter_var($url, FILTER_VALIDATE_URL);
    }
}
