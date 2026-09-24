<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\PluginBundle\Bundle\PluginBundleBase;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;

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
        if ($installedSchema instanceof Schema && $installedSchema->hasTable(HsmTemplate::TABLE_NAME)) {
            $table = $installedSchema->getTable(HsmTemplate::TABLE_NAME);

            if (!$table->hasColumn('type')) {
                $factory->getDatabase()->executeQuery(
                    'ALTER TABLE '.HsmTemplate::TABLE_NAME
                    ." ADD COLUMN type VARCHAR(191) NOT NULL DEFAULT '".HsmTemplate::TYPE_TEXT."'"
                );
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
