<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[Group('area:dispatch-hsm')]
#[Group('area:templates')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
class HsmTemplateProviderTest extends TestCase
{
    private const REQUIRED         = 'mautic.core.value.required';
    private const NUMBER_REQUIRED  = 'mautic.n8ndispatch.hsmtemplate.error.number_required';
    private const IMAGE_EXTENSION  = 'mautic.n8ndispatch.hsmtemplate.error.dialoghsm_image_extension';
    private const PROVIDER_INVALID = 'mautic.n8ndispatch.hsmtemplate.error.provider_invalid';

    /**
     * @return array<string, list<string>> the messages of the violations, by property path
     */
    private function errors(HsmTemplate $template): array
    {
        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $found     = [];

        foreach ($validator->validate($template) as $violation) {
            $found[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $found;
    }

    private function template(string $provider, ?string $router, ?int $number, string $type = HsmTemplate::TYPE_TEXT): HsmTemplate
    {
        $template = (new HsmTemplate())
            ->setName('t')
            ->setHsmTemplate('tpl')
            ->setText('Hi {{1}}')
            ->setVariablesJson(json_encode(['1' => ['source' => 'static', 'value' => 'x']]))
            ->setType($type)
            ->setProvider($provider)
            ->setRouter($router)
            ->setWhatsappNumberId($number);

        $template->setCards(array_fill(0, HsmTemplate::CARD_SLOTS, ''));

        return $template;
    }

    public function testANewTemplateGoesThroughN8n(): void
    {
        $template = new HsmTemplate();

        $this->assertSame(HsmTemplate::PROVIDER_N8N, $template->getProvider());
        $this->assertNull($template->getWhatsappNumberId());
        $this->assertFalse($template->usesDialogHsm());
    }

    public function testTheDialogHsmProviderIsKnownAsSuch(): void
    {
        $template = (new HsmTemplate())->setProvider(HsmTemplate::PROVIDER_DIALOGHSM);

        $this->assertTrue($template->usesDialogHsm());
    }

    public function testN8nNeedsTheRouterAndNotTheNumber(): void
    {
        $this->assertSame([], $this->errors($this->template(HsmTemplate::PROVIDER_N8N, 'blip-a', null)));
        $this->assertSame(['router' => [self::REQUIRED]], $this->errors($this->template(HsmTemplate::PROVIDER_N8N, '', null)));
        $this->assertSame(['router' => [self::REQUIRED]], $this->errors($this->template(HsmTemplate::PROVIDER_N8N, null, 7)));
        $this->assertSame(['router' => [self::REQUIRED]], $this->errors($this->template(HsmTemplate::PROVIDER_N8N, '   ', null)));
    }

    public function testDialogHsmNeedsTheNumberAndNotTheRouter(): void
    {
        $this->assertSame([], $this->errors($this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, 7)));
        $this->assertSame([], $this->errors($this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, 1)));
        $this->assertSame([], $this->errors($this->template(HsmTemplate::PROVIDER_DIALOGHSM, 'old-router', 7)));
        $this->assertSame(['whatsappNumberId' => [self::NUMBER_REQUIRED]], $this->errors($this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, null)));
        $this->assertSame(['whatsappNumberId' => [self::NUMBER_REQUIRED]], $this->errors($this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, 0)));
    }

    public function testDialogHsmAcceptsTheCarousel(): void
    {
        $carousel = $this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, 7, HsmTemplate::TYPE_CAROUSEL);
        $carousel->setCards(array_merge(['https://x.test/a', 'https://x.test/b'], array_fill(0, 8, '')));

        // The carousel cards go to the DialogHSM as they are: any https link, with or without an extension (the extension
        // rule is the image type's alone, so the first card is extensionless on purpose).
        $this->assertSame([], $this->errors($carousel));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedImageLinks(): array
    {
        return [
            'png'            => ['https://x.test/a.png'],
            'jpg'            => ['https://x.test/a.jpg'],
            'jpeg uppercase' => ['https://x.test/a.JPEG'],
            'with a query'   => ['https://x.test/a.png?v=3&t=abc'],
        ];
    }

    /**
     */
    #[DataProvider('acceptedImageLinks')]
    public function testDialogHsmAcceptsAnImageLinkTheDialogHsmCanTurnIntoAHeader(string $link): void
    {
        $image = $this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, 7, HsmTemplate::TYPE_IMAGE);
        $image->setCards(array_merge([$link], array_fill(0, 9, '')));

        $this->assertSame([], $this->errors($image));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedImageLinks(): array
    {
        return [
            'no extension' => ['https://x.test/media/123'],
            'gif'          => ['https://x.test/a.gif'],
            'pdf'          => ['https://x.test/a.pdf'],
            'png in the path, not at the end' => ['https://x.test/a.png/view'],
        ];
    }

    /**
     */
    #[DataProvider('refusedImageLinks')]
    public function testDialogHsmRefusesAnImageLinkItWouldDropFromTheMessage(string $link): void
    {
        // The DialogHSM makes the header only for jpg, jpeg and png: any other link is silently left out and the Meta
        // refuses the message for the missing header.
        $image = $this->template(HsmTemplate::PROVIDER_DIALOGHSM, null, 7, HsmTemplate::TYPE_IMAGE);
        $image->setCards(array_merge([$link], array_fill(0, 9, '')));

        $this->assertSame(['cards' => [self::IMAGE_EXTENSION]], $this->errors($image));
    }

    public function testN8nAcceptsAnImageLinkWithoutAnExtension(): void
    {
        $image = $this->template(HsmTemplate::PROVIDER_N8N, 'blip-a', null, HsmTemplate::TYPE_IMAGE);
        $image->setCards(array_merge(['https://x.test/media/123'], array_fill(0, 9, '')));

        $this->assertSame([], $this->errors($image));
    }

    public function testN8nKeepsTheCarousel(): void
    {
        $carousel = $this->template(HsmTemplate::PROVIDER_N8N, 'blip-a', null, HsmTemplate::TYPE_CAROUSEL);
        $carousel->setCards(array_merge(['https://x.test/a.png', 'https://x.test/b.png'], array_fill(0, 8, '')));

        $this->assertSame([], $this->errors($carousel));
    }

    public function testAnUnknownProviderIsRefusedAlone(): void
    {
        $this->assertSame(['provider' => [self::PROVIDER_INVALID]], $this->errors($this->template('blip', null, null)));
    }

    public function testTheRouterColumnAcceptsNullForTemplatesSentByTheDialogHsm(): void
    {
        $metadata = new \Doctrine\ORM\Mapping\ClassMetadata(HsmTemplate::class);
        HsmTemplate::loadMetadata($metadata);

        $this->assertTrue($metadata->getFieldMapping('router')['nullable'] ?? false);
    }

    public function testTheProviderColumnDefaultsToN8nAndTheNumberAcceptsNull(): void
    {
        $metadata = new \Doctrine\ORM\Mapping\ClassMetadata(HsmTemplate::class);
        HsmTemplate::loadMetadata($metadata);

        $this->assertSame('provider', $metadata->getFieldMapping('provider')['columnName']);
        $this->assertSame(HsmTemplate::PROVIDER_N8N, $metadata->getFieldMapping('provider')['options']['default']);
        $this->assertSame('whatsapp_number_id', $metadata->getFieldMapping('whatsappNumberId')['columnName']);
        $this->assertTrue($metadata->getFieldMapping('whatsappNumberId')['nullable']);
    }
}
