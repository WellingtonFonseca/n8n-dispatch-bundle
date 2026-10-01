<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class SmsTemplateValidationTest extends TestCase
{
    private const MESSAGE = 'mautic.n8ndispatch.template.error.numeric_variables';

    /**
     * @return list<string> the messages of the violations on the text field
     */
    private function textErrors(?string $text): array
    {
        $template = new SmsTemplate();
        $template->setText($text);

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
}
