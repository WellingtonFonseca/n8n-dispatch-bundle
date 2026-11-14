<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use Mautic\CoreBundle\Model\AuditLogModel;
use MauticPlugin\N8nDispatchBundle\Service\Audit\AuditFieldFormatter;
use MauticPlugin\N8nDispatchBundle\Service\Audit\TemplateAuditLog;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderPolicy;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Twig\AuditHistoryExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Group('area:templates')]
#[Group('risk:UI')]
#[Group('risk:DADOS')]
class AuditHistoryExtensionTest extends TestCase
{
    private function extension(AuditLogModel $model): AuditHistoryExtension
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'T:'.$key);

        return new AuditHistoryExtension($model, new AuditFieldFormatter($translator, new HsmProviderPolicy(new NullHsmProviderGateway())));
    }

    public function testTheFunctionIsRegistered(): void
    {
        $names = array_map(static fn ($function): string => $function->getName(), $this->extension($this->createMock(AuditLogModel::class))->getFunctions());

        $this->assertSame(['n8ndispatch_template_audit'], $names);
    }

    public function testItReadsTheLogOfTheTemplateOfThePluginAndSaysItForTheReader(): void
    {
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->once())->method('getLogForObject')->with('hsm_template', 7, null, 200, 'N8nDispatchBundle')
            ->willReturn([['action' => 'update', 'userName' => 'Ana', 'dateAdded' => null, 'details' => ['name' => ['A', 'B']]]]);

        $rows = $this->extension($model)->rows(TemplateAuditLog::HSM_TEMPLATE, 7);

        $this->assertSame([['field' => 'T:mautic.core.name', 'old' => 'A', 'new' => 'B']], $rows[0]['changes']);
        $this->assertSame('Ana', $rows[0]['user']);
    }

    public function testATemplateWithoutAnIdOrAnUnknownKindHasNoRows(): void
    {
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->never())->method('getLogForObject');

        $this->assertSame([], $this->extension($model)->rows(TemplateAuditLog::HSM_TEMPLATE, null));
        $this->assertSame([], $this->extension($model)->rows('email', 7));
    }
}
