<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\PluginBundle\Bundle\PluginBundleBase;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;

class N8nDispatchBundle extends PluginBundleBase
{
    /**
     * Mautic only creates a plugin's tables on first install. This plugin
     * was installed before it had any entity, so the SmsTemplate table is
     * created here instead, on the version bump that introduced it — and
     * only when missing, never altering an existing table.
     *
     * @param array<string, \Doctrine\ORM\Mapping\ClassMetadata<object>>|null $metadata
     */
    public static function onPluginUpdate(Plugin $plugin, MauticFactory $factory, $metadata = null, ?Schema $installedSchema = null): void
    {
        if (empty($metadata[SmsTemplate::class])) {
            return;
        }

        if (null !== $installedSchema && $installedSchema->hasTable(SmsTemplate::TABLE_NAME)) {
            return;
        }

        self::installPluginSchema([$metadata[SmsTemplate::class]], $factory);
    }
}
