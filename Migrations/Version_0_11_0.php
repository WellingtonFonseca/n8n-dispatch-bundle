<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntry;

/**
 * Mautic 7 does not call N8nDispatchBundle::onPluginUpdate(), which creates this table on Mautic 5: a migration of the
 * plugin (run by mautic:plugins:reload when the version changes) creates the alert log table on an install that already exists.
 */
class Version_0_11_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(AlertLogEntry::TABLE_NAME));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(AlertLogEntry::TABLE_NAME);
        $index = $this->concatPrefix('n8n_dispatch_alert_log_created');

        $this->addSql(
            "CREATE TABLE {$table} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                alert VARCHAR(191) NOT NULL,
                outcome VARCHAR(191) NOT NULL,
                reason VARCHAR(191) DEFAULT NULL,
                detail LONGTEXT DEFAULT NULL,
                subject VARCHAR(191) DEFAULT NULL,
                INDEX {$index} (created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC"
        );
    }
}
