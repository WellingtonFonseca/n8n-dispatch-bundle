<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A "Send via n8n (Email)" step needs an Email that exists. The screen's form requires it
 * (Form/Type/EmailDispatchActionType.php), but the REST API of campaigns never looks at the properties of a step, so
 * a step with no Email, or with the number of a deleted one, was saved and only failed when it fired. This answers
 * the create/edit calls (one and batch) with a 400 that names the step; nothing of the call is saved.
 *
 * The body is read as it arrives (a campaign, or a list of them for a batch): only steps of this plugin's Email type
 * are looked at, every other body is left to core.
 */
class CampaignEmailStepGuardSubscriber implements EventSubscriberInterface
{
    private const CONTROLLERS = [
        'Mautic\CampaignBundle\Controller\Api\CampaignApiController::newEntityAction'    => false,
        'Mautic\CampaignBundle\Controller\Api\CampaignApiController::editEntityAction'   => false,
        'Mautic\CampaignBundle\Controller\Api\CampaignApiController::newEntitiesAction'  => true,
        'Mautic\CampaignBundle\Controller\Api\CampaignApiController::editEntitiesAction' => true,
    ];

    private const EVENT_TYPE = 'n8ndispatch.email.send';

    public function __construct(
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'guard',
        ];
    }

    public function guard(ControllerEvent $event): void
    {
        $controller = (string) $event->getRequest()->attributes->get('_controller');

        if (!array_key_exists($controller, self::CONTROLLERS)) {
            return;
        }

        $body      = $event->getRequest()->request->all();
        $campaigns = self::CONTROLLERS[$controller] ? $body : [$body];
        $steps     = $this->emailSteps($campaigns);

        if ([] === $steps) {
            return;
        }

        $message = $this->problem($steps);

        if (null !== $message) {
            $event->setController(fn (): JsonResponse => new JsonResponse(['errors' => [['code' => 400, 'message' => $message, 'type' => null]]], 400));
        }
    }

    /**
     * @param array<mixed> $campaigns
     *
     * @return list<array{name: string, email: int}> the steps of the plugin's Email type; email is 0 when none valid was given
     */
    private function emailSteps(array $campaigns): array
    {
        $steps = [];

        foreach ($campaigns as $campaign) {
            if (!is_array($campaign) || !is_array($campaign['events'] ?? null)) {
                continue;
            }

            $position = 0;

            foreach ($campaign['events'] as $step) {
                ++$position;

                if (!is_array($step) || self::EVENT_TYPE !== ($step['type'] ?? null)) {
                    continue;
                }

                $email = is_array($step['properties'] ?? null) ? ($step['properties']['email'] ?? 0) : 0;

                $steps[] = [
                    'name'  => '' !== trim((string) ($step['name'] ?? '')) ? (string) $step['name'] : (string) $position,
                    'email' => is_numeric($email) && (int) $email > 0 ? (int) $email : 0,
                ];
            }
        }

        return $steps;
    }

    /**
     * @param list<array{name: string, email: int}> $steps
     */
    private function problem(array $steps): ?string
    {
        foreach ($steps as $step) {
            if (0 === $step['email']) {
                return $this->translator->trans('mautic.n8ndispatch.campaign.error.email_not_set', ['%step%' => $step['name']], 'validators');
            }
        }

        $ids = array_values(array_unique(array_column($steps, 'email')));

        $existing = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM '.MAUTIC_TABLE_PREFIX.'emails WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER]
        ));

        foreach ($steps as $step) {
            if (!in_array($step['email'], $existing, true)) {
                return $this->translator->trans('mautic.n8ndispatch.campaign.error.email_not_found', ['%step%' => $step['name'], '%id%' => $step['email']], 'validators');
            }
        }

        return null;
    }
}
