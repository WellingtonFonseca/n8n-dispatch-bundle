<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Model;

use Mautic\CoreBundle\Entity\FormEntity;
use MauticPlugin\N8nDispatchBundle\Service\Audit\TemplateAuditLog;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * For the SMS and HSM template models: every save, publish toggle and delete goes to core's audit_log (TemplateAuditLog).
 *
 * Core's FormModel calls dispatchEvent('post_save' / 'post_delete') from saveEntity(), togglePublishStatus() and deleteEntity(), so
 * that one method sees all three. The models dispatch no events of their own (core's default is to do nothing), so this does not
 * change what they did before.
 */
trait AuditsChanges
{
    private ?TemplateAuditLog $audit = null;

    /** The audit_log "object" of the entity this model manages. */
    abstract protected function auditObject(): string;

    #[Required]
    public function setAudit(TemplateAuditLog $audit): void
    {
        $this->audit = $audit;
    }

    protected function dispatchEvent($action, &$entity, $isNew = false, ?Event $event = null): ?Event
    {
        if ($entity instanceof FormEntity) {
            if ('post_save' === $action) {
                $this->audit?->recordSave($this->auditObject(), $entity, (bool) $isNew);
            } elseif ('post_delete' === $action) {
                $this->audit?->recordDelete($this->auditObject(), (int) ($entity->deletedId ?? 0), (string) $entity->getName());
            }
        }

        return parent::dispatchEvent($action, $entity, $isNew, $event);
    }
}
