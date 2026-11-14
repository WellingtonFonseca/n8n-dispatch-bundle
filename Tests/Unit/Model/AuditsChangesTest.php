<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Model;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use MauticPlugin\N8nDispatchBundle\Service\Audit\TemplateAuditLog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('area:templates')]
#[Group('risk:DADOS')]
#[Group('risk:INFRA')]
class AuditsChangesTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function model(string $class, ?TemplateAuditLog $audit): HsmTemplateModel|SmsTemplateModel
    {
        $model = new $class(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(CorePermissions::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(Translator::class),
            $this->createMock(UserHelper::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(CoreParametersHelper::class),
        );

        if (null !== $audit) {
            $model->setAudit($audit);
        }

        return $model;
    }

    private function dispatch(object $model, string $action, object $entity, bool $isNew = false): void
    {
        (function () use ($action, $entity, $isNew): void {
            $this->dispatchEvent($action, $entity, $isNew);
        })->call($model);
    }

    public function testAnHsmSaveIsRecordedAsAnHsmTemplate(): void
    {
        $audit  = $this->createMock(TemplateAuditLog::class);
        $entity = new HsmTemplate();
        $audit->expects($this->once())->method('recordSave')->with('hsm_template', $entity, true);

        $this->dispatch($this->model(HsmTemplateModel::class, $audit), 'post_save', $entity, true);
    }

    public function testAnSmsSaveIsRecordedAsAnSmsTemplate(): void
    {
        $audit  = $this->createMock(TemplateAuditLog::class);
        $entity = new SmsTemplate();
        $audit->expects($this->once())->method('recordSave')->with('sms_template', $entity, false);

        $this->dispatch($this->model(SmsTemplateModel::class, $audit), 'post_save', $entity, false);
    }

    public function testADeleteIsRecordedWithTheIdThatWasDeletedAndTheName(): void
    {
        $audit  = $this->createMock(TemplateAuditLog::class);
        $entity = (new HsmTemplate())->setName('Gone');
        $entity->deletedId = 12;
        $audit->expects($this->once())->method('recordDelete')->with('hsm_template', 12, 'Gone');

        $this->dispatch($this->model(HsmTemplateModel::class, $audit), 'post_delete', $entity);
    }

    public function testOtherEventsAreNotRecorded(): void
    {
        $audit = $this->createMock(TemplateAuditLog::class);
        $audit->expects($this->never())->method('recordSave');
        $audit->expects($this->never())->method('recordDelete');

        foreach (['pre_save', 'pre_delete', 'pre_unpublish'] as $action) {
            $this->dispatch($this->model(HsmTemplateModel::class, $audit), $action, new HsmTemplate());
        }
    }

    public function testWithoutTheAuditServiceNothingBreaks(): void
    {
        $this->dispatch($this->model(HsmTemplateModel::class, null), 'post_save', new HsmTemplate());
        $this->dispatch($this->model(SmsTemplateModel::class, null), 'post_delete', new SmsTemplate());

        $this->assertTrue(true);
    }
}
