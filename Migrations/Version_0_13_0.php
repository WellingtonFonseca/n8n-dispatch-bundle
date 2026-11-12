<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;

/**
 * Mautic 7 does not call N8nDispatchBundle::onPluginUpdate(), which adds this column on Mautic 5: a migration of the plugin (run by
 * mautic:plugins:reload when the version changes) adds how an SMS template is sent. Existing rows stay on n8n.
 */
class Version_0_13_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        $name = $this->concatPrefix(SmsTemplate::TABLE_NAME);

        return $schema->hasTable($name) && !$schema->getTable($name)->hasColumn('provider');
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(SmsTemplate::TABLE_NAME);

        $this->addSql("ALTER TABLE {$table} ADD COLUMN provider VARCHAR(191) NOT NULL DEFAULT '".SmsTemplate::PROVIDER_N8N."'");
    }
}
