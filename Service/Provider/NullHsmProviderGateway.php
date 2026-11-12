<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

/**
 * The gateway of an instance without the other plugin: nothing is available, nothing is listed.
 */
final class NullHsmProviderGateway implements HsmProviderGateway
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function numbers(): array
    {
        return [];
    }

    public function numberName(int $id): ?string
    {
        return null;
    }

    public function send(HsmSendRequest $request): HsmSendResult
    {
        return HsmSendResult::refused('The DialogHSM plugin is not available on this instance.');
    }
}
