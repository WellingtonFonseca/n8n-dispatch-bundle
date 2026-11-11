<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Audit;

use Mautic\CoreBundle\Model\AuditLogModel;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Service\Audit\TemplateAuditLog;
use PHPUnit\Framework\TestCase;

class TemplateAuditLogTest extends TestCase
{
    private function template(int $id = 7): HsmTemplate
    {
        $template = new HsmTemplate();
        (new \ReflectionProperty($template, 'id'))->setValue($template, $id);

        return $template;
    }

    public function testASaveWritesTheChangedFieldsAsAnUpdateOfTheTemplate(): void
    {
        $template = $this->template();
        $template->setChanges(['router' => ['a', 'b'], 'text' => ['x', 'y']]);
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->once())->method('writeToLog')->with([
            'bundle'   => 'N8nDispatchBundle',
            'object'   => 'hsm_template',
            'objectId' => 7,
            'action'   => 'update',
            'details'  => ['router' => ['a', 'b'], 'text' => ['x', 'y']],
        ]);

        (new TemplateAuditLog($model))->recordSave(TemplateAuditLog::HSM_TEMPLATE, $template, false);
    }

    public function testANewTemplateIsACreate(): void
    {
        $template = $this->template();
        $template->setChanges(['name' => [null, 'A']]);
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->once())->method('writeToLog')->with($this->callback(fn (array $log): bool => 'create' === $log['action'] && 'sms_template' === $log['object']));

        (new TemplateAuditLog($model))->recordSave(TemplateAuditLog::SMS_TEMPLATE, $template, true);
    }

    public function testBookkeepingFieldsAreNotWrittenAsChanges(): void
    {
        $template = $this->template();
        $template->setChanges(['dateModified' => [null, 'now'], 'modifiedByUser' => ['a', 'b'], 'modifiedBy' => [1, 2], 'checkedOut' => [null, 'x'], 'text' => ['x', 'y']]);
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->once())->method('writeToLog')->with($this->callback(fn (array $log): bool => ['text' => ['x', 'y']] === $log['details']));

        (new TemplateAuditLog($model))->recordSave(TemplateAuditLog::HSM_TEMPLATE, $template, false);
    }

    public function testASaveWithOnlyBookkeepingWritesNothing(): void
    {
        $template = $this->template();
        $template->setChanges(['dateModified' => [null, 'now']]);
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->never())->method('writeToLog');

        (new TemplateAuditLog($model))->recordSave(TemplateAuditLog::HSM_TEMPLATE, $template, false);
        $template->setChanges([]);
        (new TemplateAuditLog($model))->recordSave(TemplateAuditLog::HSM_TEMPLATE, $template, false);
    }

    public function testADeleteKeepsTheNameUnderTheIdThatWasDeleted(): void
    {
        $model = $this->createMock(AuditLogModel::class);
        $model->expects($this->once())->method('writeToLog')->with(['bundle' => 'N8nDispatchBundle', 'object' => 'sms_template', 'objectId' => 9, 'action' => 'delete', 'details' => ['name' => 'Gone']]);

        (new TemplateAuditLog($model))->recordDelete(TemplateAuditLog::SMS_TEMPLATE, 9, 'Gone');
    }
}
