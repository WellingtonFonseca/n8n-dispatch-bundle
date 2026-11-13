<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncState;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;

/**
 * Mautic 7 does not call N8nDispatchBundle::onPluginUpdate(): this migration (run by mautic:plugins:reload when the version
 * changes) creates the Mirror sync state table and gives each poll run its kind. Existing runs are status polls.
 */
class Version_0_14_0 extends AbstractMigration
{
    private bool $tableMissing = false;

    private bool $kindMissing = false;

    protected function isApplicable(Schema $schema): bool
    {
        $runs               = $this->concatPrefix(PollRun::TABLE_NAME);
        $this->tableMissing = !$schema->hasTable($this->concatPrefix(MirrorSyncState::TABLE_NAME));
        $this->kindMissing  = $schema->hasTable($runs) && !$schema->getTable($runs)->hasColumn('kind');

        return $this->tableMissing || $this->kindMissing;
    }

    protected function up(): void
    {
        if ($this->tableMissing) {
            $this->addSql(
                'CREATE TABLE '.$this->concatPrefix(MirrorSyncState::TABLE_NAME)." (
                    email_id INT NOT NULL,
                    status VARCHAR(191) NOT NULL,
                    content_hash VARCHAR(191) NOT NULL,
                    http_status INT DEFAULT NULL,
                    error LONGTEXT DEFAULT NULL,
                    attempts INT NOT NULL,
                    source VARCHAR(191) NOT NULL,
                    attempted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    synced_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    PRIMARY KEY(email_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC"
            );
        }

        if ($this->kindMissing) {
            $this->addSql('ALTER TABLE '.$this->concatPrefix(PollRun::TABLE_NAME)." ADD COLUMN kind VARCHAR(191) NOT NULL DEFAULT '".PollRun::KIND_STATUS_POLL."'");
        }
    }
}
