<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncState;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSyncPayload;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Every Email save (UI or API) is sent to Mirror through the webhook (MirrorSync). It runs after the save, so a
 * failure never blocks it: the result is recorded and the resync job sends the Email again later.
 */
class EmailMirrorSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MirrorSync $mirrorSync,
        private MirrorSyncPayload $payload,
        private UserHelper $userHelper,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_POST_SAVE => 'onEmailPostSave',
        ];
    }

    public function onEmailPostSave(EmailEvent $event): void
    {
        if (!$this->mirrorSync->isConfigured()) {
            return;
        }

        $email  = $event->getEmail();
        $fields = $this->payload->fieldsOf($email);

        // The payload adds the same footer in memory, so $fields read before the persist give the same HTML and hash.
        if ($this->payload->hasContent($fields)) {
            $this->persistUnsubscribeFooter($email);
        }

        $this->mirrorSync->sync(
            (int) $email->getId(),
            $fields,
            $this->userHelper->getUser(true)?->getEmail(),
            MirrorSyncState::SOURCE_SAVE,
            new \DateTimeImmutable()
        );
    }

    /**
     * Saves the footer into customHtml, so Mautic's editor, preview and the dispatch snapshot have it too. Only when
     * the HTML changes. flush() on the EntityManager, not EmailModel::saveEntity(), so this event does not run again.
     */
    private function persistUnsubscribeFooter(Email $email): void
    {
        $html        = (string) $email->getCustomHtml();
        $updatedHtml = $this->payload->withFooter($html);

        if ($updatedHtml === $html) {
            return;
        }

        $email->setCustomHtml($updatedHtml);

        // Mautic entities use DEFERRED_EXPLICIT change tracking: without this, flush() writes nothing and says nothing.
        $this->entityManager->getUnitOfWork()->scheduleForDirtyCheck($email);
        $this->entityManager->flush();
    }
}
