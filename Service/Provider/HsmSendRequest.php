<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

/**
 * What one HSM message needs to be handed to another plugin, whichever it is: the contact, the template, the variables
 * already resolved and the images. toArray() is also what the log shows as the body of the dispatch.
 */
final class HsmSendRequest
{
    /** @var array<int|string, string> number => text, in the order of the numbers */
    public readonly array $variables;

    /** @var array<int, array{type: string, value: string}> position of the button in the template => its kind (url, copy_code) and value */
    public readonly array $buttonVariables;

    /**
     * @param array<int|string, string>                                    $variables the resolved {{n}} values, by number
     * @param list<array{card_index: int, header_image_link: string}>      $cards     the files of the image, video, document and carousel types
     * @param array<int|string, array{type: string, value: string}>        $buttonVariables the buttons that take a value (a URL with {{1}}, a code to copy), by the button's position in the template
     */
    public function __construct(
        public readonly int $contactId,
        public readonly string $phone,
        public readonly string $language,
        public readonly string $template,
        public readonly string $type,
        array $variables,
        public readonly array $cards,
        public readonly int $numberId,
        public readonly ?int $campaignId,
        public readonly ?int $campaignEventId,
        array $buttonVariables = [],
    ) {
        // The other plugin takes the variables as a list in the order of the numbers: {{2}} before {{1}} would swap them.
        ksort($variables, SORT_NUMERIC);
        $this->variables = $variables;

        $buttons = [];

        foreach ($buttonVariables as $index => $button) {
            if (is_array($button) && isset($button['type'], $button['value'])) {
                $buttons[(int) $index] = ['type' => (string) $button['type'], 'value' => (string) $button['value']];
            }
        }

        ksort($buttons);
        $this->buttonVariables = $buttons;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'contact_id'         => $this->contactId,
            'phone'              => $this->phone,
            'language'           => $this->language,
            'template'           => $this->template,
            'type'               => $this->type,
            'variables'          => $this->variables,
            'whatsapp_number_id' => $this->numberId,
        ];

        if ([] !== $this->cards) {
            $data['cards'] = $this->cards;
        }

        if ([] !== $this->buttonVariables) {
            $data['button_variables'] = $this->buttonVariables;
        }

        return $data;
    }

    /**
     * The request again from what the log kept of it (toArray()), for a resend. Null when the log has no usable request.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?int $campaignId, ?int $campaignEventId): ?self
    {
        foreach (['contact_id', 'phone', 'language', 'template', 'type', 'whatsapp_number_id'] as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key])) {
                return null;
            }
        }

        $variables = is_array($data['variables'] ?? null) ? array_map('strval', array_filter($data['variables'], 'is_scalar')) : [];
        $cards     = is_array($data['cards'] ?? null) ? array_values($data['cards']) : [];
        $buttons   = is_array($data['button_variables'] ?? null) ? $data['button_variables'] : [];

        return new self((int) $data['contact_id'], (string) $data['phone'], (string) $data['language'], (string) $data['template'], (string) $data['type'], $variables, $cards, (int) $data['whatsapp_number_id'], $campaignId, $campaignEventId, $buttons);
    }

    /**
     * @param array<int|string, string> $variables
     */
    public function withVariables(array $variables): self
    {
        return new self($this->contactId, $this->phone, $this->language, $this->template, $this->type, $variables, $this->cards, $this->numberId, $this->campaignId, $this->campaignEventId, $this->buttonVariables);
    }

    /**
     * The number of the first variable (or the name of the first button) with no text, or null. A "0" is a value.
     */
    public function firstEmptyVariable(): int|string|null
    {
        foreach ($this->variables as $number => $value) {
            if ('' === trim($value)) {
                return $number;
            }
        }

        // The Meta refuses a button parameter with no text, too.
        foreach ($this->buttonVariables as $index => $button) {
            if ('' === trim($button['value'])) {
                return 'button '.($index + 1);
            }
        }

        return null;
    }
}
