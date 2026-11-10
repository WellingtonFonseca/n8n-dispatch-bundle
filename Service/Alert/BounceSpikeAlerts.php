<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The alert `campaign.bounce_spike`, checked on the cron ticks (EventListener/BounceSpikeCheckSubscriber). Volume
 * decides: a campaign alerts only with at least the minimum of sends in the window and the bounce share at or over the
 * percent of the settings.
 */
class BounceSpikeAlerts
{
    private const CHECK_EVERY_SECONDS = 300;
    private const COOLDOWN_SECONDS    = 21600;

    public function __construct(
        private AlertSettings $settings,
        private AlertThrottle $throttle,
        private AlertSender $sender,
        private BounceSpikeReader $reader,
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @return int how many alerts went out
     */
    public function check(\DateTimeImmutable $now): int
    {
        $settings = $this->settings->current();

        if (!$settings['enabled'] || !$this->throttle->claim('bounce_check', self::CHECK_EVERY_SECONDS)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->reader->offenders($now->modify('-'.$settings['bounceWindow'].' minutes'), $settings['bounceMinSends'], $settings['bouncePercent']) as $row) {
            $row['window_minutes'] = $settings['bounceWindow'];
            $row['limit_percent']  = $settings['bouncePercent'];
            $row['url']            = $this->router->generate('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $row['campaign_id']], UrlGeneratorInterface::ABSOLUTE_URL);

            $sent += (int) $this->sender->send('campaign.bounce_spike', $row, 'bounce_spike:'.$row['campaign_id'], self::COOLDOWN_SECONDS);
        }

        return $sent;
    }
}
