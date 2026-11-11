<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Audit;

use Mautic\CoreBundle\Entity\FormEntity;
use Mautic\CoreBundle\Model\AuditLogModel;

/**
 * Writes what changed in an SMS or HSM template to core's audit_log, the table core already uses for emails, segments and
 * campaigns: who, when, and {field: [old, new]}. Core writes it from event subscribers; these two models dispatch no events, so
 * Model/AuditsChanges calls this on every save, publish toggle and delete.
 */
class TemplateAuditLog
{
    public const BUNDLE = 'N8nDispatchBundle';

    public const HSM_TEMPLATE = 'hsm_template';
    public const SMS_TEMPLATE = 'sms_template';

    /** Bookkeeping core sets on every save: it is not something a person changed. */
    private const NOISE = ['dateAdded', 'dateModified', 'createdBy', 'createdByUser', 'modifiedBy', 'modifiedByUser', 'checkedOut', 'checkedOutBy', 'checkedOutByUser'];

    public function __construct(private AuditLogModel $auditLog)
    {
    }

    public function recordSave(string $object, FormEntity $entity, bool $isNew): void
    {
        $changes = array_diff_key($entity->getChanges(), array_flip(self::NOISE));

        // A save with nothing changed (Apply twice) leaves no line, like core's subscribers.
        if ([] === $changes) {
            return;
        }

        $this->write($object, (int) $entity->getId(), $isNew ? 'create' : 'update', $changes);
    }

    public function recordDelete(string $object, int $deletedId, string $name): void
    {
        $this->write($object, $deletedId, 'delete', ['name' => $name]);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function write(string $object, int $id, string $action, array $details): void
    {
        $this->auditLog->writeToLog([
            'bundle'   => self::BUNDLE,
            'object'   => $object,
            'objectId' => $id,
            'action'   => $action,
            'details'  => $details,
        ]);
    }
}
