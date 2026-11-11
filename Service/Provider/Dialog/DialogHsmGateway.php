<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider\Dialog;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendResult;
use Psr\Log\LoggerInterface;

/**
 * The gateway of an instance that has the DialogHSM plugin. It is the only class of this plugin that names that
 * plugin's classes, and Config/services.php registers it only where they exist (the folder is also kept out of the
 * automatic service loading): on any other instance it is never read, never autoloaded.
 *
 * "Available" is what the DialogHSM itself asks before it sends: its integration is published. The numbers are the
 * published ones of its own table, read through Doctrine, so the DialogHSM keeps owning that data.
 */
final class DialogHsmGateway implements HsmProviderGateway
{
    // The DialogHSM keeps this default in three places of its own code and offers no way to read it: copied here.
    private const DEFAULT_BASE_URL = 'https://waba-v2.360dialog.io/messages';

    // The links the DialogHSM turns into a header (DialogHSMApi::buildHeaderParameter): any other is left out of the message.
    private const IMAGE_HEADER_LINK = '/\.(jpe?g|png)(\?.*)?$/i';

    private ?bool $available = null;

    public function __construct(
        private readonly IntegrationsHelper $integrations,
        private readonly EntityManagerInterface $entityManager,
        private readonly SendWhatsAppMessageHandler $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available ??= $this->integrationIsPublished();
    }

    public function numbers(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $numbers = [];

        foreach ($this->entityManager->getRepository(WhatsAppNumber::class)->findBy(['isPublished' => true], ['name' => 'ASC']) as $number) {
            $numbers[(int) $number->getId()] = (string) $number->getName();
        }

        return $numbers;
    }

    public function numberName(int $id): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $number = $this->entityManager->getRepository(WhatsAppNumber::class)->find($id);

        return null !== $number && $number->getIsPublished() ? (string) $number->getName() : null;
    }

    /**
     * Goes through the DialogHSM's own handler, not its API class: that is what writes its message log, its entry on the
     * contact's timeline and the link its webhook follows by wamid. What happens after the plugin accepts the message is its
     * to track.
     */
    public function send(HsmSendRequest $request): HsmSendResult
    {
        if (!$this->isAvailable()) {
            return HsmSendResult::refused('The DialogHSM plugin is not available on this instance.');
        }

        $number = $this->entityManager->getRepository(WhatsAppNumber::class)->find($request->numberId);

        if (null === $number || !$number->getIsPublished()) {
            return HsmSendResult::refused(sprintf('The DialogHSM number #%d does not exist or is not published.', $request->numberId));
        }

        $apiKey = (string) $number->getApiKey();

        if ('' === $apiKey) {
            return HsmSendResult::refused(sprintf('The DialogHSM number "%s" has no API key.', (string) $number->getName()));
        }

        if ('image' === $request->type && 1 !== preg_match(self::IMAGE_HEADER_LINK, (string) ($request->cards[0]['header_image_link'] ?? ''))) {
            return HsmSendResult::refused('The image link must end in .jpg, .jpeg or .png: the DialogHSM does not build the header for other links.');
        }

        $message = new SendWhatsAppMessage(
            leadId:             $request->contactId,
            phone:              $request->phone,
            apiKey:             $apiKey,
            baseUrl:            $this->baseUrlOf($number),
            payloadData:        $this->payloadData($request),
            templateName:       $request->template,
            whatsAppNumberName: (string) $number->getName(),
            campaignId:         $request->campaignId,
            campaignEventId:    $request->campaignEventId,
        );

        try {
            $answer = ($this->handler)($message, false, false, true);
        } catch (\Throwable $e) {
            $this->logger->error('N8N Dispatch: the DialogHSM failed while sending a message.', ['error' => $e->getMessage(), 'contact_id' => $request->contactId]);

            return HsmSendResult::refused($e->getMessage());
        }

        if (!empty($answer['success'])) {
            return HsmSendResult::accepted($answer['wamid'] ?? null, $answer['http_status'] ?? null, $answer['response'] ?? null);
        }

        return HsmSendResult::refused(($answer['error'] ?? '') ?: 'The DialogHSM refused the message.', $answer['http_status'] ?? null, $answer['response'] ?? null);
    }

    /**
     * The payload the DialogHSM reads: content is the template, vars the names of the body variables in order, each one a key
     * with its value; url_arquivo is the header file and cards the carousel.
     *
     * @return array<int|string, mixed>
     */
    private function payloadData(HsmSendRequest $request): array
    {
        $data = ['content' => $request->template, 'language' => $request->language];

        if ([] !== $request->variables) {
            $data['vars'] = implode(',', array_keys($request->variables));
        }

        foreach ($request->variables as $number => $value) {
            $data[$number] = $value;
        }

        if ('image' === $request->type) {
            $data['url_arquivo'] = $request->cards[0]['header_image_link'];
        }

        if ('carousel' === $request->type) {
            $data['cards'] = $request->cards;
        }

        return $data;
    }

    private function baseUrlOf(WhatsAppNumber $number): string
    {
        $own = rtrim((string) $number->getBaseUrl(), '/');

        if ('' !== $own) {
            return $own;
        }

        $keys = $this->integrations->getIntegration(DialogHSMIntegration::NAME)->getIntegrationConfiguration()->getApiKeys() ?? [];

        return rtrim((string) ($keys['base_url'] ?? ''), '/') ?: self::DEFAULT_BASE_URL;
    }

    /**
     * A container built before the plugin was installed does not know its integration yet: that is "not available"
     * (cache:clear fixes it), logged so the cause is visible.
     */
    private function integrationIsPublished(): bool
    {
        try {
            return $this->integrations->getIntegration(DialogHSMIntegration::NAME)->getIntegrationConfiguration()->getIsPublished();
        } catch (IntegrationNotFoundException $e) {
            $this->logger->warning('N8N Dispatch: the DialogHSM integration is not known to this container; run cache:clear.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
