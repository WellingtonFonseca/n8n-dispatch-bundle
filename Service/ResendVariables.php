<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use MauticPlugin\N8nDispatchBundle\Resolver\LocaleConventions;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;

/**
 * The variables of a resend: what the template and its mapping give for the
 * contact TODAY, next to what the first dispatch sent. The first dispatch
 * only knows the values it resolved then, so a variable mapped (or fixed)
 * after the failure is missing from its body; the Dispatches screen shows
 * both and lets the user confirm or correct each value before the resend.
 *
 * Read from the template as it is now, like a first dispatch would: the
 * Email's mapping (Variables tab, else the step's own), the SMS/HSM
 * template's mapping, the template's language.
 */
class ResendVariables
{
    public const STATE_OK       = 'ok';
    public const STATE_EMPTY    = 'empty';
    public const STATE_UNMAPPED = 'unmapped';

    public function __construct(
        private EntityManagerInterface $em,
        private EmailVariablesRepository $emailVariables,
        private SmsTemplateModel $smsTemplates,
        private HsmTemplateModel $hsmTemplates,
        private VariableResolver $resolver,
        private TemplateVariableScanner $scanner,
        private VariableMappingChecker $mappingChecker,
    ) {
    }

    /**
     * What the resend form shows, resolved ONCE (the resolver asks the database for Custom Object values): one row per
     * variable of the template (the value the first dispatch sent, the value of today and the state of today's value),
     * and the message of an SMS / HSM.
     *
     * @return array{rows: list<array{name: string, first: string, current: string, state: string}>, message: array{template: string, filled: string}|null}
     */
    public function form(LeadEventLog $log): array
    {
        $source  = $this->sourceOf($log);
        $mapping = $this->mappingOf($source);
        $values  = $this->resolver->resolveAll($mapping, $log->getLead(), $log->getEvent()->getCampaign(), $source['separator'], $source['language']);
        $names   = $this->namesOf($source, $mapping);
        $first   = $this->firstOf($log);
        $rows  = [];

        foreach ($names as $name) {
            $current = $values[$name] ?? '';

            $rows[] = [
                'name'    => $name,
                'first'   => $first[$name] ?? '',
                'current' => $current,
                'state'   => self::stateOf($current, $this->mappingChecker->isEntrySet($mapping[$name] ?? null)),
            ];
        }

        return ['rows' => $rows, 'message' => $this->messageOf($source, $values)];
    }

    /**
     * The message of an SMS resend: the text of today's template filled with the variables that go out. Null for the
     * other channels, whose body has no text.
     *
     * @param array<string, mixed> $variables
     */
    public function smsMessage(LeadEventLog $log, array $variables): ?string
    {
        $source = $this->sourceOf($log);

        if (DispatchTracking::CHANNEL_SMS !== $source['channel'] || null === $source['text']) {
            return null;
        }

        return TemplateVariableScanner::fill($source['text'], array_map('strval', array_filter($variables, 'is_scalar')));
    }

    /**
     * The variables of the template that have no value (empty or only spaces) in the values that would go out. A resend
     * with one of them is refused: the template needs every variable it names.
     *
     * @param array<string, mixed> $variables
     *
     * @return list<string>
     */
    public function emptyNames(LeadEventLog $log, array $variables): array
    {
        $source = $this->sourceOf($log);
        $names  = $this->namesOf($source, $this->mappingOf($source));

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => !is_scalar($variables[$name] ?? null) || '' === trim((string) $variables[$name])
        ));
    }

    /**
     * ok: has a value. empty: mapped but the contact has nothing for it today.
     * unmapped: no source filled in on the mapping (the value is empty too).
     */
    public static function stateOf(string $current, bool $mapped): string
    {
        return match (true) {
            !$mapped        => self::STATE_UNMAPPED,
            '' === $current => self::STATE_EMPTY,
            default         => self::STATE_OK,
        };
    }

    /**
     * @return array<string, string>
     */
    private function firstOf(LeadEventLog $log): array
    {
        $variables = $log->getMetadata()['n8ndispatch']['variables'] ?? [];

        return is_array($variables) ? array_map('strval', array_filter($variables, 'is_scalar')) : [];
    }

    /**
     * What the step reads today: the channel and the template's mapping, language, variable names and text.
     *
     * @return array{channel: ?string, json: string, language: string, names: list<string>, separator: string, text: ?string}
     */
    private function sourceOf(LeadEventLog $log): array
    {
        $properties = $log->getEvent()->getProperties();
        $channel    = DispatchLogReader::channelOf((string) $log->getEvent()->getType());

        return ['channel' => $channel] + match ($channel) {
            DispatchTracking::CHANNEL_EMAIL => $this->emailSource($properties),
            DispatchTracking::CHANNEL_SMS   => $this->smsSource($properties),
            default                         => $this->hsmSource($properties),
        };
    }

    /**
     * The names the form lists. A template whose text has no readable name (an HSM with positional text only) still
     * lists what its mapping holds.
     *
     * @param array{names: list<string>} $source
     * @param array<string, mixed>       $mapping
     *
     * @return list<string>
     */
    private function namesOf(array $source, array $mapping): array
    {
        return [] === $source['names'] ? array_map('strval', array_keys($mapping)) : $source['names'];
    }

    /**
     * @param array{json: string} $source
     *
     * @return array<string, mixed>
     */
    private function mappingOf(array $source): array
    {
        $mapping = json_decode($source['json'], true);

        return is_array($mapping) ? $mapping : [];
    }

    /**
     * The SMS / HSM text as written (the form fills it as the inputs change), and filled with today's values that are not
     * empty (what the form shows before anything is typed). A variable with no value stays as {{name}}: the gap is seen in the
     * text instead of silently leaving a blank. Null for an Email, which has no text of its own, and for a template with no text.
     *
     * @param array{channel: ?string, text: ?string} $source
     * @param array<string, string>                  $values
     *
     * @return array{template: string, filled: string}|null
     */
    private function messageOf(array $source, array $values): ?array
    {
        if (DispatchTracking::CHANNEL_EMAIL === $source['channel'] || null === $source['text'] || '' === trim($source['text'])) {
            return null;
        }

        return ['template' => $source['text'], 'filled' => TemplateVariableScanner::fill($source['text'], array_filter($values, static fn (string $value): bool => '' !== $value))];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array{json: string, language: string, names: list<string>, separator: string, text: ?string}
     */
    private function emailSource(array $properties): array
    {
        $emailId = (int) ($properties['email'] ?? 0);
        $email   = $emailId > 0 ? $this->em->find(Email::class, $emailId) : null;
        $json    = $emailId > 0 ? $this->emailVariables->getVariablesJsonForEmail($emailId) : null;

        return [
            'json'      => $json ?? (string) ($properties['variablesJson'] ?? '{}'),
            'language'  => $email ? (string) $email->getLanguage() : LocaleConventions::DEFAULT_LOCALE,
            'names'     => $email ? $this->scanner->extractFromEmail($email->getSubject(), $email->getCustomHtml()) : [],
            'separator' => '<br>',
            'text'      => null,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array{json: string, language: string, names: list<string>, separator: string, text: ?string}
     */
    private function smsSource(array $properties): array
    {
        $template = ($id = (int) ($properties['smsTemplate'] ?? 0)) > 0 ? $this->smsTemplates->getEntity($id) : null;
        // Steps saved before templates existed carry their own text and mapping.
        $text = null === $template ? (string) ($properties['text'] ?? '') : (string) $template->getText();

        return [
            'json'      => null === $template ? (string) ($properties['variablesJson'] ?? '{}') : (string) ($template->getVariablesJson() ?? '{}'),
            'language'  => null === $template ? LocaleConventions::DEFAULT_LOCALE : $template->getLanguage(),
            'names'     => $this->scanner->extract($text),
            'separator' => ', ',
            'text'      => $text,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array{json: string, language: string, names: list<string>, separator: string, text: ?string}
     */
    private function hsmSource(array $properties): array
    {
        $template = ($id = (int) ($properties['hsmTemplateId'] ?? 0)) > 0 ? $this->hsmTemplates->getEntity($id) : null;

        return [
            'json'      => null === $template ? (string) ($properties['variablesJson'] ?? '{}') : (string) ($template->getVariablesJson() ?? '{}'),
            'language'  => null === $template ? LocaleConventions::DEFAULT_LOCALE : $template->getLanguage(),
            'names'     => null === $template ? [] : $this->scanner->extract((string) $template->getText()),
            'separator' => ', ',
            'text'      => null === $template ? null : (string) $template->getText(),
        ];
    }
}
