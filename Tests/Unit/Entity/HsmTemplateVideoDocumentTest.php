<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\Validation\HsmCards;
use MauticPlugin\N8nDispatchBundle\Entity\Validation\HsmProviderFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[Group('area:dispatch-hsm')]
#[Group('area:templates')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
class HsmTemplateVideoDocumentTest extends TestCase
{
    /**
     * @return list<string> the messages of the violations
     */
    private function errors(string $provider, string $type, string $link): array
    {
        $template = (new HsmTemplate())
            ->setName('t')
            ->setHsmTemplate('tpl')
            ->setText('Hi {{1}}')
            ->setVariablesJson(json_encode(['1' => ['source' => 'static', 'value' => 'x']]))
            ->setType($type)
            ->setProvider($provider)
            ->setRouter(HsmTemplate::PROVIDER_N8N === $provider ? 'r' : null)
            ->setWhatsappNumberId(HsmTemplate::PROVIDER_DIALOGHSM === $provider ? 3 : null);
        $template->setCards(array_merge([$link], array_fill(1, HsmTemplate::CARD_SLOTS - 1, '')));

        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $messages  = [];

        foreach ($validator->validate($template) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function types(): array
    {
        return ['video' => [HsmTemplate::TYPE_VIDEO], 'document' => [HsmTemplate::TYPE_DOCUMENT]];
    }

    #[DataProvider('types')]
    public function testTheFileIsRequired(string $type): void
    {
        $this->assertContains(HsmCards::FILE_REQUIRED, $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, $type, ''));
    }

    #[DataProvider('types')]
    public function testTheFileMustBeAnHttpsUrl(string $type): void
    {
        $this->assertContains(HsmCards::FILE_BAD_URL, $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, $type, 'http://x.test/a.mp4'));
    }

    #[DataProvider('types')]
    public function testN8nDoesNotTakeThisType(string $type): void
    {
        $this->assertContains(HsmProviderFields::TYPE_NOT_FOR_N8N, $this->errors(HsmTemplate::PROVIDER_N8N, $type, 'https://x.test/a.mp4'));
    }

    public function testAVideoNeedsMp4AndADocumentNeedsPdfOnTheDialogHsm(): void
    {
        $this->assertSame([], $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, HsmTemplate::TYPE_VIDEO, 'https://x.test/a.mp4?v=1'));
        $this->assertSame([], $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, HsmTemplate::TYPE_DOCUMENT, 'https://x.test/a.PDF'));
        $this->assertContains(HsmProviderFields::VIDEO_EXTENSION, $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, HsmTemplate::TYPE_VIDEO, 'https://x.test/a.mov'));
        $this->assertContains(HsmProviderFields::DOCUMENT_EXTENSION, $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, HsmTemplate::TYPE_DOCUMENT, 'https://x.test/a.docx'));
    }

    public function testTheImageRuleStillHolds(): void
    {
        $this->assertContains(HsmProviderFields::IMAGE_EXTENSION, $this->errors(HsmTemplate::PROVIDER_DIALOGHSM, HsmTemplate::TYPE_IMAGE, 'https://x.test/a.gif'));
    }
}
