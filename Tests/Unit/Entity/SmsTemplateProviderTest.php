<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[Group('area:dispatch-sms')]
#[Group('area:templates')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
class SmsTemplateProviderTest extends TestCase
{
    private const INVALID = 'mautic.n8ndispatch.smstemplate.error.provider_invalid';

    /**
     * @return list<string> the messages of the violations on the provider
     */
    private function providerErrors(string $provider): array
    {
        $template = (new SmsTemplate())->setName('t')->setText('Hi')->setProvider($provider);
        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();
        $found     = [];

        foreach ($validator->validate($template) as $violation) {
            if ('provider' === $violation->getPropertyPath()) {
                $found[] = (string) $violation->getMessage();
            }
        }

        return $found;
    }

    public function testANewTemplateGoesThroughN8n(): void
    {
        $this->assertSame(SmsTemplate::PROVIDER_N8N, (new SmsTemplate())->getProvider());
    }

    public function testTheProviderIsKept(): void
    {
        $this->assertSame('other', (new SmsTemplate())->setProvider('other')->getProvider());
    }

    public function testN8nIsAValidProvider(): void
    {
        $this->assertSame([], $this->providerErrors(SmsTemplate::PROVIDER_N8N));
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $this->assertSame([self::INVALID], $this->providerErrors('infobip'));
        $this->assertSame([self::INVALID], $this->providerErrors(''));
    }

    public function testTheProviderColumnDefaultsToN8n(): void
    {
        $metadata = new ClassMetadata(SmsTemplate::class);
        SmsTemplate::loadMetadata($metadata);

        $this->assertSame('provider', $metadata->getFieldMapping('provider')['columnName']);
        $this->assertSame(SmsTemplate::PROVIDER_N8N, $metadata->getFieldMapping('provider')['options']['default']);
    }
}
