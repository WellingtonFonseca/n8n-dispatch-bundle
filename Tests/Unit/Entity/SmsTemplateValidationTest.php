<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class SmsTemplateValidationTest extends TestCase
{
    private const MESSAGE  = 'mautic.n8ndispatch.template.error.numeric_variables';
    private const UNMAPPED = 'mautic.n8ndispatch.template.error.unmapped_variables';

    /**
     * @param array<string, mixed>|null $mapping the saved variables mapping; null maps every variable of the text
     *
     * @return list<string> the messages of the violations on the text field
     */
    private function textErrors(?string $text, ?array $mapping = null): array
    {
        $template = new SmsTemplate();
        $template->setText($text);
        $template->setVariablesJson(json_encode($mapping ?? $this->mapEvery((string) $text)));

        $validator  = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $violations = $validator->validate($template);

        $messages = [];
        foreach ($violations as $violation) {
            if ('text' === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function mapEvery(string $text): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $text, $matches);

        $mapping = [];
        foreach ($matches[1] as $name) {
            $mapping[$name] = ['source' => 'static', 'value' => 'x'];
        }

        return $mapping;
    }

    public function testNumericVariablesAreAccepted(): void
    {
        $this->assertSame([], $this->textErrors('Hello {{1}}, your class {{ 2 }} starts at {{3}}.'));
    }

    public function testATextWithoutVariablesIsAccepted(): void
    {
        $this->assertSame([], $this->textErrors('Just a reminder, nothing to fill in.'));
    }

    public function testANamedVariableIsRejectedWithOneGenericMessage(): void
    {
        $this->assertSame([self::MESSAGE], $this->textErrors('Hello {{valor}}'));
    }

    public function testSeveralWrongVariablesStillGiveOneMessage(): void
    {
        $this->assertSame([self::MESSAGE], $this->textErrors('{{1}} {{valor}} {{n8n_nome}} {{ nome completo }}'));
    }

    public function testAMixedTextIsRejected(): void
    {
        $this->assertSame([self::MESSAGE], $this->textErrors('{{1}} and {{curso}}'));
    }

    public function testABlankTextKeepsOnlyTheRequiredMessage(): void
    {
        $this->assertNotContains(self::MESSAGE, $this->textErrors(null));
        $this->assertNotContains(self::MESSAGE, $this->textErrors(''));
    }

    public function testAVariableWithoutAMappingIsRejected(): void
    {
        $this->assertSame([self::UNMAPPED], $this->textErrors('Hello {{1}}', []));
    }

    public function testABlankStaticValueCountsAsUnmapped(): void
    {
        $this->assertSame([self::UNMAPPED], $this->textErrors('Hello {{1}}', ['1' => ['source' => 'static', 'value' => '  ']]));
    }

    public function testOneUnmappedVariableAmongMappedOnesIsRejected(): void
    {
        $mapping = ['1' => ['source' => 'static', 'value' => 'a']];

        $this->assertSame([self::UNMAPPED], $this->textErrors('{{1}} {{2}}', $mapping));
    }

    public function testEveryKindOfFilledInSourceIsAccepted(): void
    {
        $mapping = [
            '1' => ['source' => 'static', 'value' => 'a'],
            '2' => ['source' => 'field', 'field' => 'firstname'],
            '3' => ['source' => 'custom_object', 'customObject' => 'courses', 'customObjectField' => 'name'],
        ];

        $this->assertSame([], $this->textErrors('{{1}} {{2}} {{3}}', $mapping));
    }

    public function testAnIncompleteFieldOrCustomObjectSourceIsRejected(): void
    {
        $this->assertSame([self::UNMAPPED], $this->textErrors('{{1}}', ['1' => ['source' => 'field', 'field' => '']]));
        $this->assertSame([self::UNMAPPED], $this->textErrors('{{1}}', ['1' => ['source' => 'custom_object', 'customObject' => 'courses']]));
    }

    public function testNoVariablesNeedNoMapping(): void
    {
        $this->assertSame([], $this->textErrors('Just a reminder.', []));
    }

    public function testBrokenMappingJsonCountsAsUnmapped(): void
    {
        $template = new SmsTemplate();
        $template->setText('Hello {{1}}');
        $template->setVariablesJson('not json');

        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $messages  = [];
        foreach ($validator->validate($template) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        $this->assertContains(self::UNMAPPED, $messages);
    }

    public function testTheNumericMessageComesFirstAndTheMappingStepsAside(): void
    {
        $this->assertSame([self::MESSAGE], $this->textErrors('{{1}} {{valor}}', []));
    }
}
