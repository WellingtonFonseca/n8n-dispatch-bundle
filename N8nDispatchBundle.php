<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\PluginBundle\Bundle\PluginBundleBase;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchCallback;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariables;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\Resolver\LocaleConventions;

class N8nDispatchBundle extends PluginBundleBase
{
    /**
     * Mautic only creates a plugin's tables on first install. This plugin
     * was installed before it had either entity, so their tables are
     * created here instead, each on the version bump that introduced it —
     * and only when missing.
     *
     * @param array<string, \Doctrine\ORM\Mapping\ClassMetadata<object>>|null $metadata
     */
    public static function onPluginUpdate(Plugin $plugin, MauticFactory $factory, $metadata = null, ?Schema $installedSchema = null): void
    {
        $toInstall = [];

        if (!empty($metadata[SmsTemplate::class]) && (null === $installedSchema || !$installedSchema->hasTable(SmsTemplate::TABLE_NAME))) {
            $toInstall[] = $metadata[SmsTemplate::class];
        }

        if (!empty($metadata[HsmTemplate::class]) && (null === $installedSchema || !$installedSchema->hasTable(HsmTemplate::TABLE_NAME))) {
            $toInstall[] = $metadata[HsmTemplate::class];
        }

        if (!empty($metadata[EmailVariables::class]) && (null === $installedSchema || !$installedSchema->hasTable(EmailVariables::TABLE_NAME))) {
            $toInstall[] = $metadata[EmailVariables::class];
        }

        foreach ([DispatchTracking::class => DispatchTracking::TABLE_NAME, DispatchCallback::class => DispatchCallback::TABLE_NAME, PollRun::class => PollRun::TABLE_NAME] as $entity => $table) {
            if (!empty($metadata[$entity]) && (null === $installedSchema || !$installedSchema->hasTable($table))) {
                $toInstall[] = $metadata[$entity];
            }
        }

        if ([] !== $toInstall) {
            self::installPluginSchema($toInstall, $factory);
        }

        // installPluginSchema() only CREATEs a missing table — deliberately
        // (see that method's own doc comment): a full Doctrine SchemaTool
        // diff/update, run against just this plugin's own class metadata,
        // would compare against the *entire* introspected database schema
        // (SchemaTool::createSchemaForComparison() only narrows that scope
        // when a schema_assets_filter is already configured) and could
        // emit DROP statements for every other table it doesn't know
        // about. So a field added to, or renamed on, an *existing* table
        // (like 'type' and 'hsm_id'->'hsm_template' below) gets its own
        // narrow, explicit, single-column ALTER instead — installs new
        // enough to get the column right from the CREATE above skip
        // these (hasColumn()/hasTable() are already as expected).
        // 'language' (added to both Template tables): existing rows — about
        // 30 HSM templates in production — get 'pt_BR' from the column
        // default, so nobody has to open and re-save them.
        foreach ([SmsTemplate::TABLE_NAME, HsmTemplate::TABLE_NAME] as $tableName) {
            if ($installedSchema instanceof Schema && $installedSchema->hasTable($tableName) && !$installedSchema->getTable($tableName)->hasColumn('language')) {
                $factory->getDatabase()->executeQuery(
                    'ALTER TABLE '.$tableName
                    ." ADD COLUMN language VARCHAR(191) NOT NULL DEFAULT '".LocaleConventions::DEFAULT_LOCALE."'"
                );
            }
        }

        // 0.7.0: the history is tied to the campaign log and holds the dispatch attempts too, so
        // it survives a resend. Existing rows keep working: kind defaults to 'callback', the log id
        // stays empty until `n8ndispatch:status:backfill` fills it, tracking_id may now be empty.
        if ($installedSchema instanceof Schema && $installedSchema->hasTable(DispatchCallback::TABLE_NAME)) {
            $table      = $installedSchema->getTable(DispatchCallback::TABLE_NAME);
            $connection = $factory->getDatabase();
            $name       = DispatchCallback::TABLE_NAME;

            if (!$table->hasColumn('campaign_log_id')) {
                $connection->executeQuery("ALTER TABLE {$name} ADD COLUMN campaign_log_id INT NULL");
                $connection->executeQuery("CREATE INDEX n8n_dispatch_callback_log ON {$name} (campaign_log_id)");
            }

            if (!$table->hasColumn('kind')) {
                $connection->executeQuery("ALTER TABLE {$name} ADD COLUMN kind VARCHAR(191) NOT NULL DEFAULT '".DispatchCallback::KIND_CALLBACK."'");
            }

            if (!$table->hasColumn('ref_type')) {
                $connection->executeQuery("ALTER TABLE {$name} ADD COLUMN ref_type VARCHAR(191) NULL");
            }

            if (!$table->hasColumn('ref_value')) {
                $connection->executeQuery("ALTER TABLE {$name} ADD COLUMN ref_value VARCHAR(191) NULL");
            }

            if ($table->getColumn('tracking_id')->getNotnull()) {
                $connection->executeQuery("ALTER TABLE {$name} MODIFY tracking_id INT NULL");
            }
        }

        // 0.9.0: body_json and message are real JSON columns (they were text). body_json: whatever is in
        // it that is not valid JSON (nothing should be) is emptied first, or the ALTER would refuse.
        // message: the old text of each row is wrapped as {"message": "<text>"}, the shape it has now.
        if ($installedSchema instanceof Schema && $installedSchema->hasTable(DispatchCallback::TABLE_NAME)) {
            $callbackTable = $installedSchema->getTable(DispatchCallback::TABLE_NAME);
            $connection    = $factory->getDatabase();
            $name          = DispatchCallback::TABLE_NAME;

            if ($callbackTable->hasColumn('body_json') && !($callbackTable->getColumn('body_json')->getType() instanceof \Doctrine\DBAL\Types\JsonType)) {
                $connection->executeQuery("UPDATE {$name} SET body_json = NULL WHERE body_json IS NOT NULL AND NOT JSON_VALID(body_json)");
                $connection->executeQuery("ALTER TABLE {$name} MODIFY body_json JSON NULL");
            }

            if ($callbackTable->hasColumn('message') && !($callbackTable->getColumn('message')->getType() instanceof \Doctrine\DBAL\Types\JsonType)) {
                $connection->executeQuery("UPDATE {$name} SET message = JSON_OBJECT('message', message) WHERE message IS NOT NULL");
                $connection->executeQuery("ALTER TABLE {$name} MODIFY message JSON NULL");
            }
        }

        if ($installedSchema instanceof Schema && $installedSchema->hasTable(DispatchTracking::TABLE_NAME) && !$installedSchema->getTable(DispatchTracking::TABLE_NAME)->hasColumn('campaign_log_id')) {
            $factory->getDatabase()->executeQuery('ALTER TABLE '.DispatchTracking::TABLE_NAME.' ADD COLUMN campaign_log_id INT NULL');
        }

        if ($installedSchema instanceof Schema && $installedSchema->hasTable(HsmTemplate::TABLE_NAME)) {
            $table = $installedSchema->getTable(HsmTemplate::TABLE_NAME);

            if (!$table->hasColumn('type')) {
                $factory->getDatabase()->executeQuery(
                    'ALTER TABLE '.HsmTemplate::TABLE_NAME
                    ." ADD COLUMN type VARCHAR(191) NOT NULL DEFAULT '".HsmTemplate::TYPE_TEXT."'"
                );
            }

            // 0.10.0: the carousel type keeps its image URLs here.
            if (!$table->hasColumn('cards')) {
                $factory->getDatabase()->executeQuery('ALTER TABLE '.HsmTemplate::TABLE_NAME.' ADD COLUMN cards JSON NULL');
            }

            // 'hsmId' renamed to 'hsmTemplate' (the field is the
            // WhatsApp-side template descriptor string, not an id) — an
            // install that already has the old column gets it renamed in
            // place, keeping whatever rows it already has.
            if ($table->hasColumn('hsm_id') && !$table->hasColumn('hsm_template')) {
                $factory->getDatabase()->executeQuery(
                    'ALTER TABLE '.HsmTemplate::TABLE_NAME.' RENAME COLUMN hsm_id TO hsm_template'
                );
            }
        }
    }
}
