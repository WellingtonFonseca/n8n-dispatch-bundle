<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives the campaign canvas card (Resources/views/Event/_email_send.html.twig)
 * the name of the template the event really dispatches:
 * {{ n8ndispatch_event_template_name(event['type'], event['properties']) }}.
 * The event's own name is free text typed in the modal and can point at a
 * different template than the one picked, so the card shows both.
 */
class EventTemplateExtension extends AbstractExtension
{
    public function __construct(
        private EmailModel $emailModel,
        private SmsTemplateModel $smsTemplateModel,
        private HsmTemplateModel $hsmTemplateModel,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('n8ndispatch_event_template_name', [$this, 'templateName']),
        ];
    }

    /**
     * @param mixed $properties the event's properties, as the canvas template sees them
     *
     * @return string|null the template's name, or null when none is picked or it no longer exists
     */
    public function templateName(mixed $type, mixed $properties): ?string
    {
        if (!is_array($properties)) {
            return null;
        }

        [$model, $key] = match ($type) {
            'n8ndispatch.email.send' => [$this->emailModel, 'email'],
            'n8ndispatch.sms.send'   => [$this->smsTemplateModel, 'smsTemplate'],
            'n8ndispatch.hsm.send'   => [$this->hsmTemplateModel, 'hsmTemplateId'],
            default                  => [null, null],
        };

        $id = (int) ($properties[$key] ?? 0);

        if (null === $model || $id <= 0) {
            return null;
        }

        $name = $model->getEntity($id)?->getName();

        return is_string($name) && '' !== $name ? $name : null;
    }
}
