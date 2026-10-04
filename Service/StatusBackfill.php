<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;

/**
 * Brings the dispatches made before the status poll existed into it: walks
 * the campaign logs of the three n8n actions and starts tracking the id(s) of
 * each real dispatch, dated with the moment it was originally made. The poll
 * then asks n8n about them like about any other, and the Timeline card shows
 * their callback.
 *
 * Safe to run again: an id already tracked is left alone. Goes through the
 * logs in id order, a page at a time.
 */
class StatusBackfill
{
    public function __construct(
        private EntityManagerInterface $em,
        private DispatchLogReader $reader,
        private StatusTracker $tracker,
    ) {
    }

    /**
     * @return array{scanned: int, eligible: int, registered: int, alreadyTracked: int, skipped: int}
     *                                                                                                 'registered' is what would be registered, on a dry run
     */
    public function run(int $sinceDays, int $batchSize, bool $dryRun, \DateTimeImmutable $now, bool $reset = false): array
    {
        $summary    = ['scanned' => 0, 'eligible' => 0, 'registered' => 0, 'alreadyTracked' => 0, 'skipped' => 0];
        $connection = $this->em->getConnection();
        $logTable   = $this->em->getClassMetadata(LeadEventLog::class)->getTableName();
        $eventTable = $this->em->getClassMetadata(Event::class)->getTableName();
        $utc        = new \DateTimeZone('UTC');
        $since      = $now->setTimezone($utc)->modify('-'.$sinceDays.' days')->format('Y-m-d H:i:s');
        $types      = DispatchLogReader::eventTypes();
        // the event types are this plugin's own constants, never user input
        $in         = implode(',', array_map(fn (string $type): string => "'".$type."'", $types));
        $sql        = "SELECT l.id, l.date_triggered, l.metadata, e.type FROM {$logTable} l"
            ." INNER JOIN {$eventTable} e ON e.id = l.event_id"
            ." WHERE e.type IN ({$in}) AND l.is_scheduled = 0 AND l.date_triggered >= :since AND l.id > :last"
            .' ORDER BY l.id ASC LIMIT '.max(1, $batchSize);

        $last = 0;

        // --reset: the whole history goes first and is rebuilt from the logs below.
        if ($reset && !$dryRun) {
            $this->tracker->reset();
        }

        do {
            $rows = $connection->fetchAllAssociative($sql, ['since' => $since, 'last' => $last]);

            foreach ($rows as $row) {
                $last = (int) $row['id'];
                ++$summary['scanned'];

                $channel  = DispatchLogReader::channelOf((string) $row['type']);
                $metadata = $this->decode($row['metadata']);
                $dispatch = null !== $channel && null !== $metadata ? $this->reader->dispatchOf($channel, $metadata) : null;

                if (null === $dispatch) {
                    ++$summary['skipped'];

                    continue;
                }

                ++$summary['eligible'];
                $when    = new \DateTimeImmutable((string) $row['date_triggered'], $utc);
                $refs    = $dispatch['refs'];
                $created = 0;

                if (!$dryRun) {
                    // The ids n8n gave start 'pending' at the moment of the dispatch.
                    $created = [] === $refs ? 0 : $this->tracker->register($channel, $refs, $when, (int) $row['id']);
                    // The dispatch itself is the first entry of the log's history (and links dispatches tracked before the link existed).
                    $this->tracker->adopt((int) $row['id'], $refs, $when, $dispatch['mode'], $dispatch['accepted'], $dispatch['response']);
                } else {
                    $created = $this->countUntracked($refs);
                }

                $summary['registered'] += $created;
                $summary['alreadyTracked'] += count($refs) - $created;
            }
        } while (count($rows) >= max(1, $batchSize));

        return $summary;
    }

    /**
     * @param array<string, int|string> $refs
     */
    private function countUntracked(array $refs): int
    {
        $untracked = 0;

        foreach ($refs as $refType => $value) {
            if (!$this->tracker->isTracked($refType, (string) $value)) {
                ++$untracked;
            }
        }

        return $untracked;
    }

    /**
     * The log's metadata column is a PHP-serialized array.
     *
     * @return array<string, mixed>|null
     */
    private function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : null;
    }
}
