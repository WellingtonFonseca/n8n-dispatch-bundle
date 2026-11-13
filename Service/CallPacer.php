<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * Keeps a pause between two calls to n8n, counted from the start of the previous call, so a long queue does not
 * reach n8n as one burst. No pause before the first call. The sleeper (milliseconds) is injectable for tests.
 */
class CallPacer
{
    private ?float $lastCallAt = null;

    public function __construct(private ?\Closure $sleeper = null)
    {
    }

    public function pause(int $delayMs): void
    {
        $now = microtime(true);

        if ($delayMs > 0 && null !== $this->lastCallAt) {
            $wait = (int) round($delayMs - ($now - $this->lastCallAt) * 1000);

            if ($wait > 0) {
                null !== $this->sleeper ? ($this->sleeper)($wait) : usleep($wait * 1000);
                $now = microtime(true);
            }
        }

        $this->lastCallAt = $now;
    }
}
