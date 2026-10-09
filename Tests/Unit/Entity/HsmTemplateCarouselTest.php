<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class HsmTemplateCarouselTest extends TestCase
{
    private const MIN = 'mautic.n8ndispatch.hsmtemplate.error.cards_min';
    private const GAP = 'mautic.n8ndispatch.hsmtemplate.error.cards_gap';
    private const URL = 'mautic.n8ndispatch.hsmtemplate.error.cards_url';

    /**
     * @param list<string> $cards the ten slots from the first, as the form sends them
     *
     * @return list<string> the messages of the violations on the cards
     */
    private function cardErrors(string $type, array $cards): array
    {
        $template = new HsmTemplate();
        $template->setType($type);
        $template->setCards($cards);

        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $messages  = [];
        foreach ($validator->validate($template) as $violation) {
            if ('cards' === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    public function testTwoImagesFromTheFirstSlotAreAccepted(): void
    {
        $this->assertSame([], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, ['https://x.test/a.png', 'https://x.test/b.png']));
    }

    public function testTenImagesAreAccepted(): void
    {
        $this->assertSame([], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, array_map(fn (int $n) => "https://x.test/$n.png", range(1, 10))));
    }

    public function testOneImageIsNotEnough(): void
    {
        $this->assertSame([self::MIN], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, ['https://x.test/a.png']));
        $this->assertSame([self::MIN], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, []));
    }

    public function testACardAfterAHoleBlocksTheSave(): void
    {
        // Card 4 filled with card 3 empty: would send card_index 0, 1, 3.
        $cards = ['https://x.test/1.png', 'https://x.test/2.png', '', 'https://x.test/4.png'];

        $this->assertSame([self::GAP], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, $cards));
    }

    public function testTwoImagesNotStartingAtTheFirstSlotAreAHole(): void
    {
        $this->assertSame([self::GAP], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, ['', 'https://x.test/2.png', 'https://x.test/3.png']));
    }

    public function testEachImageMustBeAnHttpsUrl(): void
    {
        $this->assertSame([self::URL], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, ['https://x.test/a.png', 'not a url']));
        $this->assertSame([self::URL], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, ['https://x.test/a.png', 'javascript:alert(1)']));
        $this->assertSame([self::URL], $this->cardErrors(HsmTemplate::TYPE_CAROUSEL, ['https://x.test/a.png', 'http://x.test/b.png']));
    }

    public function testOtherTypesIgnoreTheCards(): void
    {
        $this->assertSame([], $this->cardErrors(HsmTemplate::TYPE_TEXT, []));
        $this->assertSame([], $this->cardErrors(HsmTemplate::TYPE_TEXT, ['', 'garbage']));
    }

    public function testThePayloadHoldsTheFilledCardsWithTheirPosition(): void
    {
        $template = new HsmTemplate();
        $template->setCards([' https://x.test/a.png ', 'https://x.test/b.png', 'https://x.test/c.png']);

        $this->assertSame(
            [
                ['card_index' => 0, 'header_image_link' => 'https://x.test/a.png'],
                ['card_index' => 1, 'header_image_link' => 'https://x.test/b.png'],
                ['card_index' => 2, 'header_image_link' => 'https://x.test/c.png'],
            ],
            $template->getCardsPayload()
        );
    }

    public function testTheCardsAlwaysHaveTenSlots(): void
    {
        $this->assertCount(10, (new HsmTemplate())->getCards());

        $template = new HsmTemplate();
        $template->setCards(array_fill(0, 15, 'https://x.test/a.png'));
        $this->assertCount(10, $template->getCards());
    }

    public function testARowWithNoCardsStoredReadsAsEmptySlots(): void
    {
        // What Doctrine does for a row saved before the carousel existed: the NULL column goes straight into the property.
        $template = new HsmTemplate();
        $property = new \ReflectionProperty(HsmTemplate::class, 'cards');
        $property->setValue($template, null);

        $this->assertSame(array_fill(0, 10, ''), $template->getCards());
        $this->assertSame([], $template->getCardsPayload());
    }
}
