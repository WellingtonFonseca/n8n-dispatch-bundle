<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

/**
 * What the other plugin answered to one HSM message: it took it ("accepted") or it did not, and why. What happens to an
 * accepted message afterwards (delivered, read, refused by the Meta) is the other plugin's to track, not ours.
 */
final class HsmSendResult
{
    /**
     * @param array<string, mixed>|null $response
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $wamid,
        public readonly ?int $httpStatus,
        public readonly ?string $error,
        public readonly ?array $response,
    ) {
    }

    /**
     * @param array<string, mixed>|null $response
     */
    public static function accepted(?string $wamid, ?int $httpStatus, ?array $response): self
    {
        return new self(true, $wamid, $httpStatus, null, $response);
    }

    /**
     * @param array<string, mixed>|null $response
     */
    public static function refused(string $error, ?int $httpStatus = null, ?array $response = null): self
    {
        return new self(false, null, $httpStatus, $error, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accepted'    => $this->accepted,
            'wamid'       => $this->wamid,
            'http_status' => $this->httpStatus,
            'error'       => $this->error,
            'response'    => $this->response,
        ];
    }
}
