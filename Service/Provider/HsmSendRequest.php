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

    /**
     * @param array<int|string, string>                                    $variables the resolved {{n}} values, by number
     * @param list<array{card_index: int, header_image_link: string}>      $cards     the images of the image and carousel types
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
    ) {
        // The other plugin takes the variables as a list in the order of the numbers: {{2}} before {{1}} would swap them.
        ksort($variables, SORT_NUMERIC);
        $this->variables = $variables;
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

        return new self((int) $data['contact_id'], (string) $data['phone'], (string) $data['language'], (string) $data['template'], (string) $data['type'], $variables, $cards, (int) $data['whatsapp_number_id'], $campaignId, $campaignEventId);
    }

    /**
     * @param array<int|string, string> $variables
     */
    public function withVariables(array $variables): self
    {
        return new self($this->contactId, $this->phone, $this->language, $this->template, $this->type, $variables, $this->cards, $this->numberId, $this->campaignId, $this->campaignEventId);
    }

    /**
     * The number of the first variable with no text, or null. A "0" is a value.
     */
    public function firstEmptyVariable(): int|string|null
    {
        foreach ($this->variables as $number => $value) {
            if ('' === trim($value)) {
                return $number;
            }
        }

        return null;
    }
}
