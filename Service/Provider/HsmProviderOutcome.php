<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;

/**
 * What the log of an HSM step says about a message handed to another plugin (its 'n8ndispatch' card, written by
 * HsmCampaignTriggerSubscriber): whether it is one, and how our part ended. Nothing here reads that plugin: what happens to the
 * message after it accepted it is its own to show.
 */
final class HsmProviderOutcome
{
    public const SENT   = 'sent';
    public const FAILED = 'failed';

    /**
     * @param array<string, mixed> $metadata the metadata of the campaign log
     */
    public static function isProviderDispatch(array $metadata): bool
    {
        $card = $metadata['n8ndispatch'] ?? null;

        return is_array($card) && HsmTemplate::PROVIDER_DIALOGHSM === ($card['mode'] ?? null);
    }

    /**
     * 'sent' when the other plugin took the message, 'failed' when it refused it, null when nothing was sent (a test or a paused
     * step) or the log is not a dispatch through a provider.
     *
     * @param array<string, mixed> $metadata
     */
    public static function outcomeOf(array $metadata): ?string
    {
        if (!self::isProviderDispatch($metadata)) {
            return null;
        }

        $outcome = $metadata['n8ndispatch']['outcome'] ?? null;

        return in_array($outcome, [self::SENT, self::FAILED], true) ? $outcome : null;
    }

    /**
     * What the log keeps of a message handed to the provider: the request as the body and, once it was sent, the answer.
     *
     * @return array<string, mixed>
     */
    public static function card(HsmSendRequest $request, string $status, ?HsmSendResult $result): array
    {
        $card = ['mode' => HsmTemplate::PROVIDER_DIALOGHSM, 'status' => $status, 'request' => $request->toArray()];

        if (null !== $result) {
            $card += ['outcome' => $result->accepted ? self::SENT : self::FAILED, 'wamid' => $result->wamid, 'result' => $result->toArray()];
        }

        return $card;
    }

    /**
     * The metadata of the log after a resend: the new card in place of the old one, the failure marks of the first send gone,
     * the note (who resent it and when) kept so the card says it; a refused resend marks the log failed again.
     *
     * @param array<string, mixed> $old
     *
     * @return array<string, mixed>
     */
    public static function metadataAfterResend(array $old, HsmSendRequest $request, HsmSendResult $result, string $note): array
    {
        $new = array_diff_key($old, array_flip(['errors', 'failed', 'reason', 'n8ndispatch_resend']));

        $new['n8ndispatch']        = self::card($request, 'production', $result);
        $new['n8ndispatch_resend'] = $note;

        if (!$result->accepted) {
            $new['failed'] = 1;
            $new['reason'] = (string) $result->error;
        }

        return $new;
    }
}
