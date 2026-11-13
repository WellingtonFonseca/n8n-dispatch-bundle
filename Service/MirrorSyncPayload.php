<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\EventListener\EmailSubscriber as CoreEmailSubscriber;
use MauticPlugin\N8nDispatchBundle\UnsubscribeVariable;

/**
 * Builds the email.save payload from an Email's fields. The save (EmailMirrorSyncSubscriber) and the resync job
 * (MirrorResync) use it, so both send the same HTML and the same hash for the same content. It never writes anything.
 *
 * The fields are the array of fieldsOf(): the job reads them from the database row, the save from the entity.
 */
class MirrorSyncPayload
{
    public const FOOTER_START = '<!-- n8ndispatch:unsubscribe-footer:start -->';
    public const FOOTER_END   = '<!-- n8ndispatch:unsubscribe-footer:end -->';

    /** The Email fields Mirror receives: the keys of fieldsOf() and the column aliases of MirrorSyncStateRepository. */
    public const FIELDS = ['subject', 'customHtml', 'preheaderText', 'fromName', 'fromAddress'];

    /**
     * @return array{subject: ?string, customHtml: ?string, preheaderText: ?string, fromName: ?string, fromAddress: ?string}
     */
    public function fieldsOf(Email $email): array
    {
        return [
            'subject'       => $email->getSubject(),
            'customHtml'    => $email->getCustomHtml(),
            'preheaderText' => $email->getPreheaderText(),
            'fromName'      => $email->getFromName(),
            'fromAddress'   => $email->getFromAddress(),
        ];
    }

    /**
     * The fields of a database row (MirrorSyncStateRepository), with the same keys and types as fieldsOf().
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, ?string>
     */
    public function fieldsOfRow(array $row): array
    {
        $fields = [];

        foreach (self::FIELDS as $key) {
            $fields[$key] = isset($row[$key]) ? (string) $row[$key] : null;
        }

        return $fields;
    }

    /**
     * A themed Email changed only through the API can have an empty customHtml: there is nothing to sync.
     *
     * @param array<string, ?string> $fields
     */
    public function hasContent(array $fields): bool
    {
        return '' !== trim((string) ($fields['customHtml'] ?? ''));
    }

    /**
     * @param array<string, ?string> $fields
     *
     * @return array{mautic_template_id: int, subject: ?string, html: string, hash: string, from_name: ?string, from_address: ?string, modified_by_email: ?string}
     */
    public function build(int $emailId, array $fields, ?string $modifiedByEmail): array
    {
        $html = $this->html($fields);

        return [
            'mautic_template_id' => $emailId,
            'subject'            => $fields['subject'] ?? null,
            'html'               => $html,
            'hash'               => hash('sha256', $html),
            'from_name'          => $fields['fromName'] ?? null,
            'from_address'       => $fields['fromAddress'] ?? null,
            'modified_by_email'  => $modifiedByEmail,
        ];
    }

    /**
     * Changes when anything Mirror receives changes (not only the HTML): the job compares it with the last sync.
     *
     * @param array<string, ?string> $fields
     */
    public function contentHash(array $fields): string
    {
        return hash('sha256', (string) json_encode([$fields['subject'] ?? null, $this->html($fields), $fields['fromName'] ?? null, $fields['fromAddress'] ?? null]));
    }

    /**
     * The HTML with the unsubscribe footer in place: the block between FOOTER_START and FOOTER_END is replaced, never
     * appended, so it is never duplicated.
     */
    public function withFooter(string $html): string
    {
        $footer = self::FOOTER_START
            ."\n".'<p style="font-size:11px;color:#888888;text-align:center;margin:16px 0 0;">'
            .'<a href="'.UnsubscribeVariable::TOKEN.'" style="color:#888888;">Unsubscribe</a>'
            ."</p>\n"
            .self::FOOTER_END;

        $blockPattern = '/'.preg_quote(self::FOOTER_START, '/').'.*?'.preg_quote(self::FOOTER_END, '/').'/s';

        if (preg_match($blockPattern, $html)) {
            return (string) preg_replace($blockPattern, $footer, $html);
        }

        if (preg_match('/(<\/body>)/i', $html, $bodyMatch)) {
            return str_ireplace($bodyMatch[0], $footer."\n".$bodyMatch[0], $html);
        }

        return rtrim($html)."\n".$footer;
    }

    /**
     * @param array<string, ?string> $fields
     */
    private function html(array $fields): string
    {
        return $this->injectPreheader($this->withFooter((string) ($fields['customHtml'] ?? '')), $fields['preheaderText'] ?? null);
    }

    /**
     * Mautic adds the preheader only at send time (core EmailSubscriber::onEmailSendAddPreheaderText). Mirror does
     * not, so the payload gets it here, with core's own constants.
     */
    private function injectPreheader(string $html, ?string $preheaderText): string
    {
        if (!$preheaderText) {
            return $html;
        }

        $preheaderElement = CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_BEFORE.$preheaderText.CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_AFTER;

        if (preg_match(CoreEmailSubscriber::PREHEADER_HTML_SEARCH_PATTERN, $html)) {
            return (string) preg_replace(CoreEmailSubscriber::PREHEADER_HTML_REPLACE_PATTERN, $preheaderElement, $html);
        }

        if (preg_match('/(<body[^>]*>)/i', $html, $bodyMatch)) {
            return str_ireplace($bodyMatch[0], $bodyMatch[0]."\n".$preheaderElement, $html);
        }

        return $html;
    }
}
