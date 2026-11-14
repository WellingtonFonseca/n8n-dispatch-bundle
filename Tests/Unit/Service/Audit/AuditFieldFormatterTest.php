<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Audit;

use MauticPlugin\N8nDispatchBundle\Service\Audit\AuditFieldFormatter;
use MauticPlugin\N8nDispatchBundle\Service\Audit\TemplateAuditLog;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('area:templates')]
#[Group('risk:UI')]
#[Group('risk:DADOS')]
class AuditFieldFormatterTest extends TestCase
{
    private function formatter(): AuditFieldFormatter
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'T:'.$key);
        $gateway = $this->createMock(HsmProviderGateway::class);
        $gateway->method('numberName')->willReturnCallback(static fn (int $id): ?string => 3 === $id ? 'Demo 360' : null);

        return new AuditFieldFormatter($translator, new HsmProviderPolicy($gateway));
    }

    public function testAFieldIsNamedAsTheEditPageNamesIt(): void
    {
        $this->assertSame('T:mautic.core.name', $this->formatter()->label(TemplateAuditLog::HSM_TEMPLATE, 'name'));
        $this->assertSame('T:mautic.n8ndispatch.timeline.provider.number', $this->formatter()->label(TemplateAuditLog::HSM_TEMPLATE, 'whatsappNumberId'));
        $this->assertSame('T:mautic.core.form.available', $this->formatter()->label(TemplateAuditLog::SMS_TEMPLATE, 'isPublished'));
    }

    public function testTheTextAndProviderLabelsDependOnTheKindOfTemplate(): void
    {
        $this->assertSame('T:mautic.n8ndispatch.campaign.event.hsm.text', $this->formatter()->label(TemplateAuditLog::HSM_TEMPLATE, 'text'));
        $this->assertSame('T:mautic.n8ndispatch.template.details.text', $this->formatter()->label(TemplateAuditLog::SMS_TEMPLATE, 'text'));
        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.provider.label', $this->formatter()->label(TemplateAuditLog::HSM_TEMPLATE, 'provider'));
        $this->assertSame('T:mautic.n8ndispatch.smstemplate.provider.label', $this->formatter()->label(TemplateAuditLog::SMS_TEMPLATE, 'provider'));
    }

    public function testAFieldItDoesNotKnowIsShownAsStored(): void
    {
        $this->assertSame('somethingNew', $this->formatter()->label(TemplateAuditLog::HSM_TEMPLATE, 'somethingNew'));
        $this->assertSame('raw', $this->formatter()->value(TemplateAuditLog::HSM_TEMPLATE, 'somethingNew', 'raw'));
    }

    public function testTheValuesOfTheFixedChoicesAreTranslated(): void
    {
        $f = $this->formatter();

        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.provider.dialoghsm', $f->value(TemplateAuditLog::HSM_TEMPLATE, 'provider', 'dialoghsm'));
        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.type.carousel', $f->value(TemplateAuditLog::HSM_TEMPLATE, 'type', 'carousel'));
        $this->assertSame('T:mautic.n8ndispatch.template.details.active', $f->value(TemplateAuditLog::SMS_TEMPLATE, 'isPublished', '1'));
        $this->assertSame('T:mautic.n8ndispatch.template.details.inactive', $f->value(TemplateAuditLog::SMS_TEMPLATE, 'isPublished', '0'));
    }

    public function testATypeOfAnSmsIsNotTranslatedAsAnHsmType(): void
    {
        $this->assertSame('text', $this->formatter()->value(TemplateAuditLog::SMS_TEMPLATE, 'type', 'text'));
    }

    public function testTheNumberIsTheNameOfTheNumberOrTheMissingWarning(): void
    {
        $this->assertSame('Demo 360', $this->formatter()->value(TemplateAuditLog::HSM_TEMPLATE, 'whatsappNumberId', '3'));
        $this->assertSame('T:mautic.n8ndispatch.hsmtemplate.number.missing', $this->formatter()->value(TemplateAuditLog::HSM_TEMPLATE, 'whatsappNumberId', '9'));
        $this->assertSame('abc', $this->formatter()->value(TemplateAuditLog::HSM_TEMPLATE, 'whatsappNumberId', 'abc'));
    }

    public function testAnEmptyValueStaysEmpty(): void
    {
        $this->assertSame('', $this->formatter()->value(TemplateAuditLog::HSM_TEMPLATE, 'provider', ''));
    }

    public function testAProviderOrTypeWithoutATextIsShownAsStoredNotAsAKey(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $formatter = new AuditFieldFormatter($translator, new HsmProviderPolicy($this->createMock(HsmProviderGateway::class)));

        $this->assertSame('future', $formatter->value(TemplateAuditLog::HSM_TEMPLATE, 'provider', 'future'));
        $this->assertSame('video', $formatter->value(TemplateAuditLog::HSM_TEMPLATE, 'type', 'video'));
    }

    public function testTheProviderOfAnSmsUsesTheSmsTexts(): void
    {
        $this->assertSame('T:mautic.n8ndispatch.smstemplate.provider.n8n', $this->formatter()->value(TemplateAuditLog::SMS_TEMPLATE, 'provider', 'n8n'));
    }
}
