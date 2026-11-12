<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use Mautic\CoreBundle\Model\AuditLogModel;
use MauticPlugin\N8nDispatchBundle\Service\Audit\AuditFieldFormatter;
use MauticPlugin\N8nDispatchBundle\Service\Audit\AuditTrail;
use MauticPlugin\N8nDispatchBundle\Service\Audit\TemplateAuditLog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The rows of the "Histórico" tab of an SMS or HSM template (edit page and details modal): what core's audit_log holds for it,
 * said in the language of the reader (Service/Audit).
 */
class AuditHistoryExtension extends AbstractExtension
{
    private const LIMIT = 200;

    public function __construct(
        private readonly AuditLogModel $auditLog,
        private readonly AuditFieldFormatter $formatter,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('n8ndispatch_template_audit', [$this, 'rows'])];
    }

    /**
     * @param string $object TemplateAuditLog::HSM_TEMPLATE or SMS_TEMPLATE
     *
     * @return list<array{date: mixed, user: string, action: string, changes: list<array{field: string, old: string, new: string}>}>
     */
    public function rows(string $object, ?int $id): array
    {
        if (null === $id || !in_array($object, [TemplateAuditLog::HSM_TEMPLATE, TemplateAuditLog::SMS_TEMPLATE], true)) {
            return [];
        }

        return AuditTrail::rows($this->auditLog->getLogForObject($object, $id, null, self::LIMIT, TemplateAuditLog::BUNDLE), $object, $this->formatter);
    }
}
