<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What Mautic itself knows about an Email that went out through its own mailer (EmailSendMode 'mautic'): the dispatch
 * screens read it here, at the moment they draw, instead of asking n8n. Nothing is copied: the Stat (sent, opened, failed),
 * the tracked clicks and the Do Not Contact list are core's tables.
 *
 * A dispatch is tied to its Stats by the ids its campaign log keeps (metadata['n8ndispatch']['statIds'], or the 'statId' of
 * the first send). Each send or resend is one Stat, so the number of Stats is the number of attempts; the state shown is the
 * one of the last attempt. No Stat at all means Mautic held the Email back (its frequency rules or its queue).
 *
 * States, from the strongest: bounced, failed, unsubscribed (after this send), clicked, opened, sent, and queued. A dispatch with
 * no Stat is 'failed' or 'skipped' when the step recorded that (the mailer refused it, or Mautic left the contact out: no
 * address, a category the contact left), else 'queued'.
 * An open is only what the tracking pixel reports: a client that blocks or caches images hides it.
 */
class MauticEmailOutcome
{
    public const QUEUED       = 'queued';
    public const SENT         = 'sent';
    public const OPENED       = 'opened';
    public const CLICKED      = 'clicked';
    public const FAILED       = 'failed';
    public const BOUNCED      = 'bounced';
    public const UNSUBSCRIBED = 'unsubscribed';
    public const SKIPPED      = 'skipped';

    /** Do Not Contact reasons of core (DoNotContact::UNSUBSCRIBED, ::BOUNCED). */
    private const DNC_UNSUBSCRIBED = 1;
    private const DNC_BOUNCED      = 2;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Whether the dispatch of this log went out through Mautic's mailer.
     *
     * @param array<string, mixed> $metadata
     */
    public static function isDirect(array $metadata): bool
    {
        $n8n = $metadata['n8ndispatch'] ?? null;

        return is_array($n8n) && 'mautic' === ($n8n['mode'] ?? null);
    }

    /**
     * The Stat ids of a dispatch, oldest first.
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<int>
     */
    public static function statIdsOf(array $metadata): array
    {
        $n8n = $metadata['n8ndispatch'] ?? null;

        if (!is_array($n8n)) {
            return [];
        }

        $ids = is_array($n8n['statIds'] ?? null) ? $n8n['statIds'] : [$n8n['statId'] ?? null];
        $ids = array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $ids);
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * The outcome the step recorded on the log ('sent', 'queued', 'skipped' or 'failed'), null when it has none.
     *
     * @param array<string, mixed> $metadata
     */
    public static function outcomeOf(array $metadata): ?string
    {
        $outcome = is_array($metadata['n8ndispatch'] ?? null) ? ($metadata['n8ndispatch']['outcome'] ?? null) : null;

        return is_string($outcome) ? $outcome : null;
    }

    /**
     * No Stat: the step says why (the mailer refused it, Mautic left the contact out), else Mautic held the Email back.
     */
    private static function withoutStat(?string $outcome): string
    {
        return match ($outcome) {
            'failed'  => self::FAILED,
            'skipped' => self::SKIPPED,
            default   => self::QUEUED,
        };
    }

    /**
     * The result the filters and the badge column know: 'success', 'error' or 'pending'.
     */
    public static function resultOf(string $state): string
    {
        return match ($state) {
            self::FAILED, self::BOUNCED, self::SKIPPED => 'error',
            self::QUEUED                => 'pending',
            default                     => 'success',
        };
    }

    /**
     * The contact a campaign log belongs to (a Timeline card knows its log, not always the contact); 0 when the log is gone.
     */
    public function leadOfLog(int $logId): int
    {
        return (int) $this->em->getConnection()->createQueryBuilder()
            ->select('lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log')
            ->where('id = :id')
            ->setParameter('id', $logId)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * The state of many dispatches at once, for the list and its filter.
     *
     * @param array<int|string, array{lead: int, statIds: list<int>, outcome?: ?string}> $logs by any key (the log id); the outcome
     *                                                                                          the step recorded, for a dispatch with no Stat
     *
     * @return array<int|string, string> the same keys => a state
     */
    public function states(array $logs): array
    {
        $allIds = [];
        foreach ($logs as $log) {
            $allIds = array_merge($allIds, $log['statIds']);
        }

        $stats = $this->stats($allIds);
        $leads = array_values(array_unique(array_map(static fn (array $log): int => $log['lead'], $logs)));

        $dnc    = $this->doNotContact($leads);
        $clicks = $this->clicks($stats);

        $states = [];
        foreach ($logs as $key => $log) {
            $mine = array_intersect_key($stats, array_flip($log['statIds']));
            $last = [] === $mine ? null : $mine[max(array_keys($mine))];

            $states[$key] = null === $last ? self::withoutStat($log['outcome'] ?? null) : $this->stateOf($last, $dnc[$log['lead']] ?? [], isset($clicks[$last['id']]));
        }

        return $states;
    }

    /**
     * Everything Mautic knows about one dispatch, for its card: the state, one entry per attempt (newest first) with
     * the open and the clicks of that send, and the Do Not Contact the contact asked for or got after the last send.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{state: string, attempts: list<array{statId: int, sentAt: ?\DateTimeImmutable, failed: bool, openedAt: ?\DateTimeImmutable, lastOpenedAt: ?\DateTimeImmutable, openCount: int, clicks: list<array{url: string, count: int, lastAt: ?\DateTimeImmutable}>}>, dnc: array{state: string, at: ?\DateTimeImmutable, comments: string}|null}
     */
    public function detail(int $leadId, array $metadata): array
    {
        $stats = $this->stats(self::statIdsOf($metadata));
        ksort($stats);
        $stats = array_values($stats);
        $dnc   = $this->doNotContact([$leadId])[$leadId] ?? [];
        $hits  = $this->hitsOf($stats);

        $attempts = [];
        foreach ($stats as $i => $stat) {
            $until      = $stats[$i + 1]['date_sent'] ?? null;
            $byUrl      = [];
            foreach ($hits[$stat['id']] ?? [] as $hit) {
                if (null !== $until && $hit['date_hit'] >= $until) {
                    continue;
                }
                $byUrl[$hit['url']]['count']  = ($byUrl[$hit['url']]['count'] ?? 0) + 1;
                $byUrl[$hit['url']]['lastAt'] = max($byUrl[$hit['url']]['lastAt'] ?? '', $hit['date_hit']);
            }

            $clicks = [];
            foreach ($byUrl as $url => $info) {
                $clicks[] = ['url' => (string) $url, 'count' => $info['count'], 'lastAt' => self::date($info['lastAt'])];
            }

            $attempts[] = [
                'statId'       => $stat['id'],
                'sentAt'       => self::date($stat['date_sent']),
                'failed'       => $stat['is_failed'],
                'openedAt'     => self::date($stat['date_read']),
                'lastOpenedAt' => self::date($stat['last_opened']),
                'openCount'    => $stat['open_count'],
                'clicks'       => $clicks,
            ];
        }

        $last  = [] === $stats ? null : $stats[array_key_last($stats)];
        $state = null === $last ? self::withoutStat(self::outcomeOf($metadata)) : $this->stateOf($last, $dnc, [] !== ($attempts[array_key_last($attempts)]['clicks'] ?? []));

        $dncShown = null;
        if (null !== $last) {
            $after = $this->dncAfter($last['date_sent'], $dnc);
            if (null !== $after) {
                $dncShown = ['state' => self::DNC_BOUNCED === $after['reason'] ? self::BOUNCED : self::UNSUBSCRIBED, 'at' => self::date($after['date_added']), 'comments' => $after['comments']];
            }
        }

        return ['state' => $state, 'attempts' => array_reverse($attempts), 'dnc' => $dncShown];
    }

    /**
     * @param array{id: int, date_sent: string, is_read: bool, is_failed: bool} $stat
     * @param list<array{reason: int, date_added: string, comments: string}>   $dnc   of the contact, channel email
     */
    private function stateOf(array $stat, array $dnc, bool $clicked): string
    {
        $after = $this->dncAfter($stat['date_sent'], $dnc);

        return match (true) {
            null !== $after && self::DNC_BOUNCED === $after['reason'] => self::BOUNCED,
            $stat['is_failed']                                        => self::FAILED,
            null !== $after                                           => self::UNSUBSCRIBED,
            $clicked                                                  => self::CLICKED,
            $stat['is_read']                                          => self::OPENED,
            default                                                   => self::SENT,
        };
    }

    /**
     * The newest unsubscribe or bounce the contact got at or after the send; a bounce wins over an unsubscribe.
     *
     * @param list<array{reason: int, date_added: string, comments: string}> $dnc
     *
     * @return array{reason: int, date_added: string, comments: string}|null
     */
    private function dncAfter(string $sentAt, array $dnc): ?array
    {
        $found = null;

        foreach ($dnc as $row) {
            if ($row['date_added'] < $sentAt || !in_array($row['reason'], [self::DNC_UNSUBSCRIBED, self::DNC_BOUNCED], true)) {
                continue;
            }

            if (null === $found || self::DNC_BOUNCED === $row['reason'] && self::DNC_BOUNCED !== $found['reason']) {
                $found = $row;
            }
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{id: int, email_id: int, lead_id: int, date_sent: string, is_read: bool, is_failed: bool, date_read: ?string, last_opened: ?string, open_count: int}>
     */
    private function stats(array $ids): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $rows = $this->em->getConnection()->createQueryBuilder()
                ->select('id, email_id, lead_id, date_sent, is_read, is_failed, date_read, last_opened, open_count')
                ->from(MAUTIC_TABLE_PREFIX.'email_stats')
                ->where('id IN (:ids)')
                ->setParameter('ids', $chunk, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $found[(int) $row['id']] = [
                    'id'          => (int) $row['id'],
                    'email_id'    => (int) $row['email_id'],
                    'lead_id'     => (int) $row['lead_id'],
                    'date_sent'   => (string) $row['date_sent'],
                    'is_read'     => (bool) (int) $row['is_read'],
                    'is_failed'   => (bool) (int) $row['is_failed'],
                    'date_read'   => null === $row['date_read'] ? null : (string) $row['date_read'],
                    'last_opened' => null === $row['last_opened'] ? null : (string) $row['last_opened'],
                    'open_count'  => (int) $row['open_count'],
                ];
            }
        }

        return $found;
    }

    /**
     * @param list<int> $leadIds
     *
     * @return array<int, list<array{reason: int, date_added: string, comments: string}>> by lead id
     */
    private function doNotContact(array $leadIds): array
    {
        $found = [];

        foreach (array_chunk($leadIds, 1000) as $chunk) {
            $rows = $this->em->getConnection()->createQueryBuilder()
                ->select('lead_id, reason, date_added, comments')
                ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact')
                ->where('lead_id IN (:leads)')
                ->andWhere('channel = :channel')
                ->setParameter('leads', $chunk, ArrayParameterType::INTEGER)
                ->setParameter('channel', 'email')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $found[(int) $row['lead_id']][] = ['reason' => (int) $row['reason'], 'date_added' => (string) $row['date_added'], 'comments' => (string) $row['comments']];
            }
        }

        return $found;
    }

    /**
     * The clicks of each Stat: the tracked-link hits of that contact on that Email from its send on. One query for
     * all the Stats (the list reads thousands), then each hit goes to the Stats it belongs to.
     *
     * @param array<int, array{id: int, email_id: int, lead_id: int, date_sent: string}> $stats
     *
     * @return array<int, list<array{url: string, date_hit: string}>> by stat id
     */
    private function hitsOf(array $stats): array
    {
        $byStat = [];
        foreach ($stats as $stat) {
            $byStat[$stat['id']] = [];
        }

        if ([] === $stats) {
            return $byStat;
        }

        $since = min(array_map(static fn (array $stat): string => $stat['date_sent'], $stats));
        $index = [];
        foreach ($stats as $stat) {
            $index[$stat['lead_id'].'|'.$stat['email_id']][] = $stat;
        }

        foreach (array_chunk(array_values(array_unique(array_map(static fn (array $stat): int => $stat['lead_id'], $stats))), 1000) as $leads) {
            $rows = $this->em->getConnection()->createQueryBuilder()
                ->select('lead_id, email_id, url, date_hit')
                ->from(MAUTIC_TABLE_PREFIX.'page_hits')
                ->where('lead_id IN (:leads)')
                ->andWhere('email_id IN (:emails)')
                ->andWhere('redirect_id IS NOT NULL')
                ->andWhere('date_hit >= :since')
                ->setParameter('leads', $leads, ArrayParameterType::INTEGER)
                ->setParameter('emails', array_values(array_unique(array_map(static fn (array $stat): int => $stat['email_id'], $stats))), ArrayParameterType::INTEGER)
                ->setParameter('since', $since)
                ->orderBy('date_hit', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                foreach ($index[(int) $row['lead_id'].'|'.(int) $row['email_id']] ?? [] as $stat) {
                    if ((string) $row['date_hit'] >= $stat['date_sent']) {
                        $byStat[$stat['id']][] = ['url' => (string) $row['url'], 'date_hit' => (string) $row['date_hit']];
                    }
                }
            }
        }

        return $byStat;
    }

    /**
     * Which of the Stats had a click (one query for all, the list only needs a yes or no).
     *
     * @param array<int, array{id: int, email_id: int, lead_id: int, date_sent: string}> $stats
     *
     * @return array<int, true> stat id => true
     */
    private function clicks(array $stats): array
    {
        $clicked = [];

        foreach ($this->hitsOf($stats) as $statId => $hits) {
            if ([] !== $hits) {
                $clicked[$statId] = true;
            }
        }

        return $clicked;
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
