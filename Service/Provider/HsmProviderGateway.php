<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

/**
 * What the HSM templates need from another plugin that can send them (today the DialogHSMBundle, 360dialog).
 *
 * This is the only door to that plugin: no other class of this plugin may name one of its classes. An instance
 * without it must boot and work as before, so the container gets NullHsmProviderGateway there (Config/services.php).
 */
interface HsmProviderGateway
{
    /**
     * Whether the other plugin is installed and can send now. Every screen and every send asks this first.
     */
    public function isAvailable(): bool;

    /**
     * The numbers a template can pick, by the name the person sees. Empty when the plugin is not available.
     *
     * @return array<int, string> id => name
     */
    public function numbers(): array;

    /**
     * The name of one number, or null when it does not exist (deleted there) or the plugin is not available.
     */
    public function numberName(int $id): ?string;
}
