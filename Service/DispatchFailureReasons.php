<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reasons a dispatch step fails, as written on the contact's History (the campaign event log): the plugin's own
 * sentences are translated into the language in use when the step runs (the user's on screen, the system's from
 * cron), and every reason starts with "N8nDispatch: " so it is clear where it comes from. What n8n answers and what
 * an exception says are not ours to translate: they go through reason() as they are.
 */
class DispatchFailureReasons
{
    public const PREFIX = 'N8nDispatch: ';

    public const SMS_TEMPLATE_NOT_FOUND = 'mautic.n8ndispatch.failure.sms_template_not_found';
    public const HSM_TEMPLATE_NOT_FOUND = 'mautic.n8ndispatch.failure.hsm_template_not_found';
    public const EMAIL_TEMPLATE_MISSING = 'mautic.n8ndispatch.failure.email_template_missing';
    public const INTEGRATION_DISABLED   = 'mautic.n8ndispatch.failure.integration_disabled';
    public const WEBHOOK_MISSING        = 'mautic.n8ndispatch.failure.webhook_missing';
    public const DO_NOT_CONTACT         = 'mautic.n8ndispatch.failure.do_not_contact';
    public const NO_PHONE               = 'mautic.n8ndispatch.failure.no_phone';
    public const HTTP_STATUS            = 'mautic.n8ndispatch.failure.http_status';
    public const EMPTY_RESPONSE         = 'mautic.n8ndispatch.failure.empty_response';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * The translated sentence, without the prefix (to build a larger reason from it).
     *
     * @param array<string, string|int> $parameters
     */
    public function text(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    /**
     * Puts the prefix on a text that is already final.
     */
    public function reason(string $text): string
    {
        return self::PREFIX.$text;
    }

    /**
     * The translated sentence with the prefix, ready to hand to PendingEvent::fail()/failAll().
     *
     * @param array<string, string|int> $parameters
     */
    public function translated(string $key, array $parameters = []): string
    {
        return $this->reason($this->text($key, $parameters));
    }
}
