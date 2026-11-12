<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;

/**
 * Mautic 7 does not call N8nDispatchBundle::onPluginUpdate(), which adds these columns on Mautic 5: a migration of the plugin (run by
 * mautic:plugins:reload when the version changes) adds how an HSM template is sent to an install that already has the table. Existing
 * rows stay on n8n; a template sent by the DialogHSM has no router, so that column takes NULL.
 */
class Version_0_12_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        $name = $this->concatPrefix(HsmTemplate::TABLE_NAME);

        return $schema->hasTable($name) && !$schema->getTable($name)->hasColumn('provider');
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(HsmTemplate::TABLE_NAME);

        $this->addSql("ALTER TABLE {$table} ADD COLUMN provider VARCHAR(191) NOT NULL DEFAULT '".HsmTemplate::PROVIDER_N8N."'");
        $this->addSql("ALTER TABLE {$table} ADD COLUMN whatsapp_number_id INT NULL");
        $this->addSql("ALTER TABLE {$table} MODIFY router VARCHAR(191) NULL");
    }
}
