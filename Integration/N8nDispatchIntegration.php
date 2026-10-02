<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Integration;

use Mautic\CoreBundle\Form\Type\StandAloneButtonType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Gives this plugin a "Settings > Plugins > N8n Dispatch" screen with two
 * plain text fields (no OAuth) — the webhook URL that receives the
 * {mautic_template_id, html, hash} payload on every Email save, and an
 * auth token sent as a plain "X-N8n-Dispatch-Token" header (matches n8n's
 * Webhook node Header Auth, which is just a literal name/value pair — no
 * Bearer/OAuth2 semantics). Today that URL points at n8n, but nothing
 * here is n8n-specific — it's just "the configured endpoint".
 */
class N8nDispatchIntegration extends AbstractIntegration
{
    public const NAME = 'N8nDispatch';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'N8n Dispatch';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * The Features tab: the status poll's on/off (off by default) and its
     * interval, plus a read-only line with the last scheduled run. See
     * Service/StatusPollSettings.php and Service/StatusPollScheduler.php.
     * The poll itself needs no cron entry — it rides on the Mautic cron.
     *
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed>|null                     $data
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        $current = StatusPollSettings::fromArray(is_array($data) ? $data : []);

        $builder->add(StatusPollSettings::KEY_ENABLED, YesNoButtonGroupType::class, [
            'label' => 'mautic.n8ndispatch.settings.status_poll.enabled',
            'data'  => $current['enabled'] ? 1 : 0,
            'attr'  => ['tooltip' => 'mautic.n8ndispatch.settings.status_poll.enabled.tooltip'],
        ]);

        $builder->add(StatusPollSettings::KEY_INTERVAL, IntegerType::class, [
            'label'       => 'mautic.n8ndispatch.settings.status_poll.interval',
            'data'        => $current['interval'],
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.n8ndispatch.settings.status_poll.interval.tooltip',
                'min'     => StatusPollSettings::MIN_INTERVAL,
                'max'     => StatusPollSettings::MAX_INTERVAL,
            ],
            'constraints' => [new Range(min: StatusPollSettings::MIN_INTERVAL, max: StatusPollSettings::MAX_INTERVAL)],
        ]);

        $builder->add(StatusPollSettings::KEY_BATCH, IntegerType::class, [
            'label'       => 'mautic.n8ndispatch.settings.status_poll.batch',
            'data'        => $current['batch'],
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.n8ndispatch.settings.status_poll.batch.tooltip',
                'min'     => StatusPollSettings::MIN_BATCH,
                'max'     => StatusPollSettings::MAX_BATCH,
            ],
            'constraints' => [new Range(min: StatusPollSettings::MIN_BATCH, max: StatusPollSettings::MAX_BATCH)],
        ]);

        $builder->add(StatusPollSettings::KEY_TIMEOUT, IntegerType::class, [
            'label'       => 'mautic.n8ndispatch.settings.status_poll.timeout',
            'data'        => $current['timeout'],
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.n8ndispatch.settings.status_poll.timeout.tooltip',
                'min'     => StatusPollSettings::MIN_TIMEOUT,
                'max'     => StatusPollSettings::MAX_TIMEOUT,
            ],
            'constraints' => [new Range(min: StatusPollSettings::MIN_TIMEOUT, max: StatusPollSettings::MAX_TIMEOUT)],
        ]);

        $builder->add(StatusPollSettings::KEY_MAX_DURATION, IntegerType::class, [
            'label'       => 'mautic.n8ndispatch.settings.status_poll.max_duration',
            'data'        => $current['maxDuration'],
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.n8ndispatch.settings.status_poll.max_duration.tooltip',
                'min'     => StatusPollSettings::MIN_TIMEOUT,
                'max'     => StatusPollSettings::MAX_MAX_DURATION,
            ],
            'constraints' => [new Range(min: StatusPollSettings::MIN_TIMEOUT, max: StatusPollSettings::MAX_MAX_DURATION)],
        ]);

        $builder->add('status_poll_last_run', TextType::class, [
            'label'      => 'mautic.n8ndispatch.settings.status_poll.last_run',
            'label_attr' => ['class' => 'control-label'],
            'data'       => $this->describeLastRun($current['interval']),
            'mapped'     => false,
            'disabled'   => true,
            'required'   => false,
            'attr'       => ['class' => 'form-control'],
        ]);

        $this->addCheckNowButton($builder);
    }

    /**
     * The "Check now" button (Assets/js/status-poll-check-now.js asks
     * n8n right away and shows the result under it).
     */
    private function addCheckNowButton(\Symfony\Component\Form\FormBuilderInterface $builder): void
    {
        $builder->add('status_poll_check_now', StandAloneButtonType::class, [
            'label' => 'mautic.n8ndispatch.settings.status_poll.check_now',
            'attr'  => [
                'class'   => 'btn btn-secondary',
                'icon'    => 'ri-refresh-line',
                'onclick' => 'Mautic.n8ndispatchCheckStatusNow(this)',
            ],
        ]);
    }

    /**
     * The text of the read-only "Last run" field. Never allowed to break the
     * settings screen — e.g. before the plugin's tables exist.
     */
    private function describeLastRun(int $intervalMinutes): string
    {
        try {
            $latest = $this->em->getRepository(PollRun::class)->latest();
        } catch (\Throwable) {
            return '';
        }

        if (null === $latest) {
            return $this->translator->trans('mautic.n8ndispatch.settings.status_poll.last_run.never');
        }

        $text = $this->translator->trans('mautic.n8ndispatch.settings.status_poll.last_run.line', [
            '%date%'    => $latest->getStartedAt()->format('Y-m-d H:i').' UTC',
            '%status%'  => $this->translator->trans('mautic.n8ndispatch.settings.status_poll.status.'.$latest->getStatus()),
            '%summary%' => str_replace("\n", ' | ', (string) $latest->getSummary()),
        ]);

        if (StatusPollSettings::isStale($latest, $intervalMinutes, new \DateTimeImmutable())) {
            $text = $this->translator->trans('mautic.n8ndispatch.settings.status_poll.last_run.stale').' '.$text;
        }

        return trim($text);
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredKeyFields(): array
    {
        return [
            'webhook_url'   => 'mautic.integration.n8ndispatch.webhook_url',
            'webhook_token' => 'mautic.integration.n8ndispatch.webhook_token',
        ];
    }
}
