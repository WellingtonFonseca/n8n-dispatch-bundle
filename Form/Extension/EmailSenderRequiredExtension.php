<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Extension;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Requires fromName and fromAddress on every Email, on every save (including the first), from the screen and
 * from the API (/api/emails): both build the Email through this same form type, so one check covers both.
 *
 * Why: EmailMirrorSyncSubscriber sends both fields to Mirror when the Email is saved, and Mirror rejects the
 * template when they are empty. The Email is already saved in Mautic by then, so the failure only reaches the
 * log: Mautic shows a saved template that never reached Mirror. Core leaves both fields optional (an empty
 * sender falls back to the system default at send time, which Mirror never sees), hence this check.
 *
 * Each field gets its own error, so the API names the missing one (fromName / fromAddress) and the screen marks
 * it; the screen also gets flash messages, because both fields live in the Advanced tab, which the message names.
 */
class EmailSenderRequiredExtension extends AbstractTypeExtension
{
    private const MESSAGE_KEY = 'mautic.n8ndispatch.email.error.sender_required';

    /**
     * Field => core's own translation key for its label on the form.
     */
    private const REQUIRED_FIELDS = [
        'fromName'    => 'mautic.email.from_name',
        'fromAddress' => 'mautic.email.from_email',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        #[Autowire(service: 'mautic.core.service.flashbag')]
        private FlashBag $flashBag,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return iterable<class-string>
     */
    public static function getExtendedTypes(): iterable
    {
        return [EmailType::class];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $email = $event->getData();

            if (!$email instanceof Email) {
                return;
            }

            $values = [
                'fromName'    => $email->getFromName(),
                'fromAddress' => $email->getFromAddress(),
            ];

            // Both fields live in this tab of the Email form. Its name and the fields' labels come from core's own
            // translations (the ones that draw the screen), so the message uses the words the user sees in the
            // installed language.
            $tab = $this->translator->trans('mautic.core.advanced');
            $isUi = !str_starts_with((string) $this->requestStack->getCurrentRequest()?->getPathInfo(), '/api/');

            foreach (self::REQUIRED_FIELDS as $field => $labelKey) {
                if ('' !== trim((string) $values[$field])) {
                    continue;
                }

                $label   = $this->translator->trans($labelKey);
                $message = $this->translator->trans(self::MESSAGE_KEY, ['%field%' => $label, '%tab%' => $tab]);

                // Core's API error handling reads $error->getCause()->getCode(), so the cause can't be null.
                $violation = new ConstraintViolation($message, null, [], $email, $field, $values[$field]);
                $event->getForm()->get($field)->addError(new FormError($message, null, [], null, $violation));

                if ($isUi) {
                    $this->flashBag->add(
                        self::MESSAGE_KEY,
                        ['%field%' => $label, '%tab%' => '<b>'.htmlspecialchars($tab, ENT_QUOTES).'</b>'],
                        FlashBag::LEVEL_ERROR,
                        'messages'
                    );
                }
            }
        });
    }
}
