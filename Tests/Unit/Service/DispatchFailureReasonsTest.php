<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener\EnUsTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class DispatchFailureReasonsTest extends TestCase
{
    private DispatchFailureReasons $reasons;

    protected function setUp(): void
    {
        $this->reasons = new DispatchFailureReasons(new EnUsTranslator());
    }

    /**
     * @return iterable<string, array{string, array<string, string|int>, string}>
     */
    public static function staticReasons(): iterable
    {
        yield 'sms template' => [DispatchFailureReasons::SMS_TEMPLATE_NOT_FOUND, ['%id%' => 7], 'N8nDispatch: SMS template #7 not found.'];
        yield 'hsm template' => [DispatchFailureReasons::HSM_TEMPLATE_NOT_FOUND, ['%id%' => 7], 'N8nDispatch: HSM template #7 not found.'];
        yield 'email template' => [DispatchFailureReasons::EMAIL_TEMPLATE_MISSING, [], 'N8nDispatch: the configured Email template no longer exists.'];
        yield 'integration' => [DispatchFailureReasons::INTEGRATION_DISABLED, [], 'N8nDispatch: the N8n Dispatch integration is not configured/enabled.'];
        yield 'webhook' => [DispatchFailureReasons::WEBHOOK_MISSING, [], 'N8nDispatch: webhook_url is not configured.'];
        yield 'dnc' => [DispatchFailureReasons::DO_NOT_CONTACT, ['%channel%' => 'sms'], 'N8nDispatch: contact is on the Do Not Contact list for sms.'];
        yield 'phone' => [DispatchFailureReasons::NO_PHONE, [], 'N8nDispatch: contact has no phone number.'];
        yield 'http' => [DispatchFailureReasons::HTTP_STATUS, ['%status%' => 500], 'N8nDispatch: webhook returned HTTP 500.'];
        yield 'empty' => [DispatchFailureReasons::EMPTY_RESPONSE, ['%action%' => 'hsm.send'], 'N8nDispatch: n8n returned an empty response for hsm.send.'];
    }

    /**
     * @dataProvider staticReasons
     *
     * @param array<string, string|int> $params
     */
    public function testTranslatedReasonsKeepTheirEnglishTextAndTheSourcePrefix(string $key, array $params, string $expected): void
    {
        $this->assertSame($expected, $this->reasons->translated($key, $params));
    }

    public function testATextThatIsAlreadyFinalOnlyGetsThePrefix(): void
    {
        // What n8n answers or an exception says: not ours to translate.
        $this->assertSame('N8nDispatch: code 404, Entity not found - contact', $this->reasons->reason('code 404, Entity not found - contact'));
        $this->assertSame('N8nDispatch: Connection refused', $this->reasons->reason('Connection refused'));
    }

    public function testTextWithoutThePrefixIsAvailableForBuildingALargerReason(): void
    {
        $this->assertSame('webhook returned HTTP 502.', $this->reasons->text(DispatchFailureReasons::HTTP_STATUS, ['%status%' => 502]));
    }

    public function testItAsksTheTranslatorForTheKeyAndTheParameters(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(DispatchFailureReasons::NO_PHONE, [])
            ->willReturn('o contato não tem número de telefone.');

        $this->assertSame('N8nDispatch: o contato não tem número de telefone.', (new DispatchFailureReasons($translator))->translated(DispatchFailureReasons::NO_PHONE));
    }
}
