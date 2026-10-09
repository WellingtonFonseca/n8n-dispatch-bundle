<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity\Validation;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Class-level rule for the "Imagem Carrossel" HSM type, ignored for every other type: at least two image URLs,
 * filled from the first slot on with no hole between them (card_index is the slot's position, so a card 4 without
 * a card 3 would send 0, 1, 3), and each one a http(s) URL. Used as a callable array in the entity's validator
 * metadata (not a closure: the validator caches that metadata, and closures can't be serialized).
 */
final class CarouselCards
{
    public const MIN_CARDS = 2;

    public const TOO_FEW = 'mautic.n8ndispatch.hsmtemplate.error.cards_min';
    public const GAP     = 'mautic.n8ndispatch.hsmtemplate.error.cards_gap';
    public const BAD_URL = 'mautic.n8ndispatch.hsmtemplate.error.cards_url';

    public static function validate(HsmTemplate $template, ExecutionContextInterface $context): void
    {
        if (HsmTemplate::TYPE_CAROUSEL !== $template->getType()) {
            return;
        }

        $filled  = 0;
        $blankAt = null;

        foreach ($template->getCards() as $index => $url) {
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

            if (!preg_match('#^https?://\S+$#i', $url) || false === filter_var($url, FILTER_VALIDATE_URL)) {
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
}
