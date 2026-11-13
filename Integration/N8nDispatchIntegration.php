<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Integration;

use Mautic\CoreBundle\Form\Type\StandAloneButtonType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntry;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Form\Type\VisiblePasswordType;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettingsForm;
use MauticPlugin\N8nDispatchBundle\Service\Changelog;
use MauticPlugin\N8nDispatchBundle\Service\EmailSendMode;
use MauticPlugin\N8nDispatchBundle\Service\LastRunReport;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSendSwitch;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSettingsForm;
use MauticPlugin\N8nDispatchBundle\Service\SettingsFields;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Gives this plugin a "Settings > Plugins > N8N Dispatch" screen with two
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

    private const ALERT_LOG_ROWS = 50;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'N8N Dispatch';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * The change log under the fields of the first tab (core draws the 'custom' note there).
     * Core asks for the other sections too, and wants its own [text, type] pair for them.
     *
     * @return array<int|string, mixed>
     */
    public function getFormNotes($section)
    {
        if ('custom' !== $section) {
            return parent::getFormNotes($section);
        }

        return [
            'custom'     => true,
            'template'   => '@N8nDispatch/Integration/_notes.html.twig',
            'parameters' => ['days' => Changelog::days(), 'alertLog' => $this->latestAlerts()],
        ];
    }

    /**
     * The rows of the log in the Alertas tab. Never allowed to break the settings screen (the table may not exist yet):
     * null then, so the tab says it could not read the log instead of "no alerts".
     *
     * @return list<array{created_at: string, alert: string, outcome: string, reason: string|null, detail: string|null, subject: string|null}>|null
     */
    private function latestAlerts(): ?array
    {
        try {
            $repository = $this->em->getRepository(AlertLogEntry::class);

            return $repository instanceof AlertLogEntryRepository ? $repository->latest(self::ALERT_LOG_ROWS) : null;
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: the alert log could not be read: '.$e->getMessage());

            return null;
        }
    }

    /**
     * The Features tab: the status poll's on/off (off by default) and its
     * interval, plus a read-only line with the last scheduled run. See
     * Service/StatusPollSettings.php and Service/StatusPollJob.php.
     * The poll itself needs no cron entry — it rides on the Mautic cron.
     *
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed>|null                     $data
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('keys' === $formArea) {
            $this->tokenAsPassword($builder, $data);
            $this->addEmailSendMode($builder, $data);

            return;
        }

        if ('features' !== $formArea) {
            return;
        }

        $current = StatusPollSettings::fromArray(is_array($data) ? $data : []);

        $text = 'mautic.n8ndispatch.settings.status_poll.';
        SettingsFields::switch($builder, StatusPollSettings::KEY_ENABLED, $current['enabled'], $text.'enabled');
        SettingsFields::number($builder, StatusPollSettings::KEY_INTERVAL, $current['interval'], StatusPollSettings::MIN_INTERVAL, StatusPollSettings::MAX_INTERVAL, $text.'interval');
        SettingsFields::number($builder, StatusPollSettings::KEY_BATCH, $current['batch'], StatusPollSettings::MIN_BATCH, StatusPollSettings::MAX_BATCH, $text.'batch');
        SettingsFields::number($builder, StatusPollSettings::KEY_DELAY, $current['delay'], StatusPollSettings::MIN_DELAY, StatusPollSettings::MAX_DELAY, $text.'delay');
        SettingsFields::number($builder, StatusPollSettings::KEY_TIMEOUT, $current['timeout'], StatusPollSettings::MIN_TIMEOUT, StatusPollSettings::MAX_TIMEOUT, $text.'timeout');
        SettingsFields::number($builder, StatusPollSettings::KEY_MAX_DURATION, $current['maxDuration'], StatusPollSettings::MIN_TIMEOUT, StatusPollSettings::MAX_MAX_DURATION, $text.'max_duration');

        $lastRun = $this->describeLastRun(PollRun::KIND_STATUS_POLL, $current['interval']);
        SettingsFields::lastRun($builder, 'status_poll_last_run', $text.'last_run', $lastRun);

        $this->addCheckNowButton($builder);

        $mirrorData = is_array($data) ? $data : [];
        $sendOn     = MirrorSendSwitch::fromArray($mirrorData);
        $resync     = MirrorResyncSettings::fromArray($mirrorData);
        // Switch off on purpose: no "cron stopped" warning.
        MirrorSettingsForm::addTo($builder, $sendOn, $resync, $this->describeLastRun(PollRun::KIND_MIRROR_RESYNC, $sendOn ? $resync['interval'] : null));

        AlertSettingsForm::addTo($builder, AlertSettings::fromArray(is_array($data) ? $data : []), $this->translator->trans('mautic.n8ndispatch.settings.alerts.tab'));
    }

    /**
     * Where the Email of a campaign step goes out, under the token (Habilitar/Autenticar tab): kept
     * with the other settings of the connection, read by Service/EmailSendMode.php.
     *
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed>|null                     $data    the saved keys, decrypted
     */
    private function addEmailSendMode($builder, $data): void
    {
        $builder->add(EmailSendMode::KEY, ChoiceType::class, [
            'label'      => 'mautic.n8ndispatch.settings.email_send_mode',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => [
                'mautic.n8ndispatch.settings.email_send_mode.n8n'    => EmailSendMode::N8N,
                'mautic.n8ndispatch.settings.email_send_mode.mautic' => EmailSendMode::MAUTIC,
            ],
            'expanded'   => false,
            'multiple'   => false,
            'data'       => EmailSendMode::fromArray(is_array($data) ? $data : []),
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.n8ndispatch.settings.email_send_mode.tooltip',
            ],
        ]);
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
     * The JSON of a read-only "Last run" block (see LastRunReport), of the status poll or of the
     * Mirror resync. Never allowed to break the settings screen — e.g. before the plugin's tables exist.
     */
    private function describeLastRun(string $kind, ?int $intervalMinutes): string
    {
        try {
            $latest = $this->em->getRepository(PollRun::class)->latest($kind);
        } catch (\Throwable) {
            return '';
        }

        if (null === $latest) {
            return $this->translator->trans('mautic.n8ndispatch.settings.status_poll.last_run.never');
        }

        $stale = null !== $intervalMinutes && StatusPollSettings::isStale($latest, $intervalMinutes, new \DateTimeImmutable());

        return LastRunReport::json(
            $kind,
            $latest->getStartedAt(),
            $this->translator->trans('mautic.n8ndispatch.settings.status_poll.status.'.$latest->getStatus()),
            $latest->getSummary(),
            $stale ? $this->translator->trans('mautic.n8ndispatch.settings.status_poll.last_run.stale') : null
        );
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

    /**
     * The auth token is drawn as a password input (dots) but keeps its value in
     * the field, so it can be looked at and checked later (a "reveal" in the
     * browser, or the page source): core's own secret-key fields (and Symfony's
     * PasswordType) come up blank and cannot be read back, which is more than
     * this token needs. Replaces the
     * plain text field core already added for the key. Still required while the
     * integration is published, as before.
     *
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed>|null                     $data    the saved keys, decrypted
     */
    private function tokenAsPassword($builder, $data): void
    {
        $builder->add('webhook_token', VisiblePasswordType::class, [
            'label'         => 'mautic.integration.n8ndispatch.webhook_token',
            'label_attr'    => ['class' => 'control-label'],
            'attr'          => [
                'class'          => 'form-control',
                'autocomplete'   => 'off',
                // The eye at the end of the field (core's "postaddon"): click to show / hide the token.
                'postaddon'      => 'ri-eye-line',
                'postaddon_attr' => [
                    'style'   => 'cursor:pointer',
                    'title'   => 'Mostrar / ocultar',
                    'onclick' => "var i=this.parentNode.querySelector('input');var s='password'===i.type;i.type=s?'text':'password';this.firstElementChild.className=s?'ri-eye-off-line':'ri-eye-line';",
                ],
            ],
            'required'      => false,
            'data'          => is_array($data) && is_string($data['webhook_token'] ?? null) ? $data['webhook_token'] : null,
            'error_bubbling' => false,
            'constraints'   => [
                new Callback(static function ($value, ExecutionContextInterface $context): void {
                    $root      = $context->getRoot();
                    $published = !$root instanceof \Symfony\Component\Form\FormInterface || !$root->has('isPublished') || (bool) $root->get('isPublished')->getData();

                    if (empty($value) && $published) {
                        $context->buildViolation('mautic.core.value.required')->addViolation();
                    }
                }),
            ],
        ]);
    }
}
