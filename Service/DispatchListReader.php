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

    private const COLUMNS = 'l.id, l.date_triggered, l.metadata, e.type, e.properties, c.name AS campaign_name, l.lead_id, ld.firstname, ld.lastname, ld.email';

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
     * @return list<array{id: int, channel: string, dispatchedAt: ?\DateTimeImmutable, campaignName: string, templateName: string, templateRoute: array{name: string, params: array<string, int|string>}|null, contactId: int, contactName: string, metadata: array<string, mixed>}>
     */
    public function read(int $page, int $limit): array
    {
        $rows = $this->baseQuery()
            ->select(self::COLUMNS)
            ->orderBy('l.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $limit))
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->mapRows($rows);
    }

    /**
     * One dispatch of the table by its log id, for the details modal; null
     * when it is not one of them (another event type, no dispatch attempted).
     *
     * @return array{id: int, channel: string, dispatchedAt: ?\DateTimeImmutable, campaignName: string, templateName: string, templateRoute: array{name: string, params: array<string, int|string>}|null, contactId: int, contactName: string, metadata: array<string, mixed>}|null
     */
    public function find(int $id): ?array
    {
        $rows = $this->baseQuery()
            ->select(self::COLUMNS)
            ->andWhere('l.id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->mapRows($rows)[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: int, channel: string, dispatchedAt: ?\DateTimeImmutable, campaignName: string, templateName: string, templateRoute: array{name: string, params: array<string, int|string>}|null, contactId: int, contactName: string, metadata: array<string, mixed>}>
     */
    private function mapRows(array $rows): array
    {
        $refs = [];
        foreach ($rows as $i => $row) {
            $refs[$i] = self::templateRef((string) $row['type'], (string) $row['properties']);
        }

        $names = $this->templateNames($refs);

        $items = [];
        foreach ($rows as $i => $row) {
            $ref     = $refs[$i];
            $channel = DispatchLogReader::channelOf((string) $row['type']) ?? '';

            $found = null !== $ref && isset($names[$ref[0]][$ref[1]]);

            $items[] = [
                'id'            => (int) $row['id'],
                'channel'       => $channel,
                'dispatchedAt'  => self::dispatchedAt($row['date_triggered']),
                'campaignName'  => (string) $row['campaign_name'],
                'templateName'  => null !== $ref ? ($names[$ref[0]][$ref[1]] ?? '#'.$ref[1]) : '-',
                'templateRoute' => $found ? self::templateRoute($ref[0], $ref[1]) : null,
                'contactId'     => (int) $row['lead_id'],
                'contactName'   => self::contactName($row['firstname'], $row['lastname'], $row['email'], (int) $row['lead_id']),
                'metadata'      => self::metadataOf($row['metadata']),
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

    /**
     * Where the template's own screen is: the native Email view, and the
     * plugin's edit page for an SMS or HSM template (their lists link there
     * too). Null for an unknown channel.
     *
     * @return array{name: string, params: array<string, int|string>}|null
     */
    public static function templateRoute(string $channel, int $id): ?array
    {
        return match ($channel) {
            DispatchTracking::CHANNEL_EMAIL => ['name' => 'mautic_email_action', 'params' => ['objectAction' => 'view', 'objectId' => $id]],
            DispatchTracking::CHANNEL_SMS   => ['name' => 'mautic_n8ndispatch.smstemplate_action', 'params' => ['objectAction' => 'edit', 'objectId' => $id]],
            DispatchTracking::CHANNEL_HSM   => ['name' => 'mautic_n8ndispatch.hsmtemplate_action', 'params' => ['objectAction' => 'edit', 'objectId' => $id]],
            default                         => null,
        };
    }

    /**
     * The log's date_triggered is stored in UTC; null when it has none.
     */
    public static function dispatchedAt(?string $dateTriggered): ?\DateTimeImmutable
    {
        if (null === $dateTriggered || '' === trim($dateTriggered)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($dateTriggered, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The log's metadata is a PHP-serialized array; the Timeline's callback
     * lookup (n8ndispatch_status) reads the tracked ids out of it.
     *
     * @return array<string, mixed>
     */
    public static function metadataOf(?string $metadata): array
    {
        $data = null === $metadata || '' === $metadata ? null : @unserialize($metadata, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
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
            ->innerJoin('e', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = e.campaign_id')
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
