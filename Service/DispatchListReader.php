<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;

/**
 * Feeds the N8n Dispatch > Dispatches table: one row per campaign log of the
 * three n8n actions that really tried to dispatch (its metadata carries the
 * 'n8ndispatch' block), newest first, with the template's name and the
 * contact's name.
 *
 * The template is not on the log: it is a property of the campaign event
 * the log belongs to ('email', 'smsTemplate' or 'hsmTemplateId'), so it is
 * read from there and its name looked up in one query per channel for the
 * whole page.
 */
class DispatchListReader
{
    private const TEMPLATE_KEY = [
        'n8ndispatch.email.send' => [DispatchTracking::CHANNEL_EMAIL, 'email'],
        'n8ndispatch.sms.send'   => [DispatchTracking::CHANNEL_SMS, 'smsTemplate'],
        'n8ndispatch.hsm.send'   => [DispatchTracking::CHANNEL_HSM, 'hsmTemplateId'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function count(): int
    {
        return (int) $this->baseQuery()
            ->select('COUNT(l.id)')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<array{id: int, channel: string, templateName: string, contactName: string}>
     */
    public function read(int $page, int $limit): array
    {
        $rows = $this->baseQuery()
            ->select('l.id, e.type, e.properties, l.lead_id, ld.firstname, ld.lastname, ld.email')
            ->orderBy('l.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $limit))
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $refs = [];
        foreach ($rows as $i => $row) {
            $refs[$i] = self::templateRef((string) $row['type'], (string) $row['properties']);
        }

        $names = $this->templateNames($refs);

        $items = [];
        foreach ($rows as $i => $row) {
            $ref     = $refs[$i];
            $channel = DispatchLogReader::channelOf((string) $row['type']) ?? '';

            $items[] = [
                'id'           => (int) $row['id'],
                'channel'      => $channel,
                'templateName' => null !== $ref ? ($names[$ref[0]][$ref[1]] ?? '#'.$ref[1]) : '-',
                'contactName'  => self::contactName($row['firstname'], $row['lastname'], $row['email'], (int) $row['lead_id']),
            ];
        }

        return $items;
    }

    /**
     * @return array{0: string, 1: int}|null channel and template id the event points at
     */
    public static function templateRef(string $eventType, string $properties): ?array
    {
        if (!isset(self::TEMPLATE_KEY[$eventType])) {
            return null;
        }

        $data = @unserialize($properties, ['allowed_classes' => false]);

        if (!is_array($data)) {
            return null;
        }

        [$channel, $key] = self::TEMPLATE_KEY[$eventType];
        $id              = (int) ($data[$key] ?? 0);

        return $id > 0 ? [$channel, $id] : null;
    }

    public static function contactName(?string $firstName, ?string $lastName, ?string $email, int $leadId): string
    {
        $name = trim(trim((string) $firstName).' '.trim((string) $lastName));

        if ('' !== $name) {
            return $name;
        }

        return '' !== trim((string) $email) ? trim((string) $email) : '#'.$leadId;
    }

    private function baseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->em->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'l')
            ->innerJoin('l', MAUTIC_TABLE_PREFIX.'campaign_events', 'e', 'e.id = l.event_id')
            ->innerJoin('l', MAUTIC_TABLE_PREFIX.'leads', 'ld', 'ld.id = l.lead_id')
            ->where('e.type IN (:types)')
            ->andWhere('l.metadata LIKE :marker')
            ->setParameter('types', DispatchLogReader::eventTypes(), ArrayParameterType::STRING)
            ->setParameter('marker', '%n8ndispatch%');
    }

    /**
     * @param array<int, array{0: string, 1: int}|null> $refs
     *
     * @return array<string, array<int, string>> channel => template id => name
     */
    private function templateNames(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (null !== $ref) {
                $ids[$ref[0]][$ref[1]] = $ref[1];
            }
        }

        $tables = [
            DispatchTracking::CHANNEL_EMAIL => MAUTIC_TABLE_PREFIX.'emails',
            DispatchTracking::CHANNEL_SMS   => MAUTIC_TABLE_PREFIX.SmsTemplate::TABLE_NAME,
            DispatchTracking::CHANNEL_HSM   => MAUTIC_TABLE_PREFIX.HsmTemplate::TABLE_NAME,
        ];

        $names = [];
        foreach ($ids as $channel => $channelIds) {
            $found = $this->em->getConnection()->createQueryBuilder()
                ->select('id, name')
                ->from($tables[$channel])
                ->where('id IN (:ids)')
                ->setParameter('ids', array_values($channelIds), ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllKeyValue();

            foreach ($found as $id => $name) {
                $names[$channel][(int) $id] = (string) $name;
            }
        }

        return $names;
    }
}
