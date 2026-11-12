<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class HsmTemplateValidationTest extends TestCase
{
    private const MESSAGE  = 'mautic.n8ndispatch.template.error.numeric_variables';
    private const UNMAPPED = 'mautic.n8ndispatch.template.error.unmapped_variables';
    private const GAP      = 'mautic.n8ndispatch.template.error.variables_gap';
    private const ZERO     = 'mautic.n8ndispatch.template.error.variables_zero';
    private const LIMIT    = 'mautic.n8ndispatch.template.error.variables_limit';

    /**
     * @param array<string, mixed>|null $mapping the saved variables mapping; null maps every variable of the text
     *
     * @return list<string> the messages of the violations on the text field
     */
    private function textErrors(?string $text, ?array $mapping = null): array
    {
        $template = new HsmTemplate();
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
        $template = new HsmTemplate();
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

    public function testASkippedNumberIsRejected(): void
    {
        $this->assertSame([self::GAP], $this->textErrors('Hello {{1}}, class {{3}}.'));
    }

    public function testTheMessageNamesTheFirstMissingNumber(): void
    {
        $template = new HsmTemplate();
        $template->setText('{{1}} {{2}} {{5}} {{6}}');
        $template->setVariablesJson(json_encode($this->mapEvery('{{1}} {{2}} {{5}} {{6}}')));

        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $found     = [];
        foreach ($validator->validate($template) as $violation) {
            if (self::GAP === $violation->getMessageTemplate()) {
                $found[] = $violation->getParameters();
            }
        }

        $this->assertSame([['%missing%' => '3', '%highest%' => '6']], $found);
    }

    public function testASequenceThatStartsAfterOneIsRejected(): void
    {
        $this->assertSame([self::GAP], $this->textErrors('Hello {{2}}'));
    }

    public function testTheOrderInTheTextAndRepeatedNumbersDoNotMatter(): void
    {
        $this->assertSame([], $this->textErrors('{{2}} then {{1}} then {{2}} and {{3}}'));
    }

    public function testSpacesInsideTheBracesDoNotHideAGap(): void
    {
        $this->assertSame([self::GAP], $this->textErrors('{{ 1 }} {{ 3 }}'));
    }

    public function testTheNumericMessageWinsOverTheGapMessage(): void
    {
        $this->assertSame([self::MESSAGE], $this->textErrors('{{1}} {{3}} {{valor}}', []));
    }

    public function testZeroIsRejectedAlone(): void
    {
        $this->assertSame([self::ZERO], $this->textErrors('Hello {{0}}'));
    }

    public function testZeroNextToOneIsRejected(): void
    {
        $this->assertSame([self::ZERO], $this->textErrors('{{0}} and {{1}}'));
    }

    public function testZeroWithAGapGivesOnlyTheZeroMessage(): void
    {
        $this->assertSame([self::ZERO], $this->textErrors('{{0}} and {{2}}'));
    }

    public function testTheNumericMessageWinsOverTheZeroMessage(): void
    {
        $this->assertSame([self::MESSAGE], $this->textErrors('{{0}} {{valor}}', []));
    }

    public function testFiftyVariablesAreAccepted(): void
    {
        $this->assertSame([], $this->textErrors($this->numbered(50)));
    }

    public function testFiftyOneVariablesAreRefused(): void
    {
        $this->assertSame([self::LIMIT], $this->textErrors($this->numbered(51)));
    }

    public function testTheLimitWinsOverAGapAndAHugeNumberAnswersAtOnce(): void
    {
        // range(1, highest) would try to build the whole list: this must not walk up to the highest number.
        $this->assertSame([self::LIMIT], $this->textErrors('{{1}} {{99999999999}}'));
        $this->assertSame([self::LIMIT], $this->textErrors('{{1}} {{51}}'));
    }

    public function testAGapBelowTheLimitStillGivesTheGapMessage(): void
    {
        $this->assertSame([self::GAP], $this->textErrors('{{1}} {{50}}'));
    }

    public function testTheMessageCarriesTheLimit(): void
    {
        $template = new HsmTemplate();
        $template->setText('{{51}}');
        $template->setVariablesJson(json_encode($this->mapEvery('{{51}}')));

        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $found     = [];
        foreach ($validator->validate($template) as $violation) {
            if (self::LIMIT === $violation->getMessageTemplate()) {
                $found[] = $violation->getParameters();
            }
        }

        $this->assertSame([['%max%' => '50']], $found);
    }

    public function testTheSameNumberWrittenTwoWaysCountsOnce(): void
    {
        // {{1}} and {{01}} are two placeholders for the scanner but one number: no hole between them.
        $this->assertSame([], $this->textErrors('{{1}} {{01}} {{2}}'));
    }

    private function numbered(int $count): string
    {
        return implode(' ', array_map(static fn (int $n): string => '{{'.$n.'}}', range(1, $count)));
    }
}
