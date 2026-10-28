<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * Deleting an Email takes its translations and A/B variants with it (the foreign keys cascade), so
 * EmailTemplateUsageFinder::inUseAmong() also counts the steps that use any of them. Run against the stack's real
 * MySQL inside a transaction rolled back at the end.
 */
class EmailFamilyUsageDbTest extends RollbackDatabaseTestCase
{
    private function finder(): EmailTemplateUsageFinder
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);

        return new EmailTemplateUsageFinder($em);
    }

    private function email(string $column = '', ?int $parent = null): int
    {
        $row = ['name' => 'ZZ-family-'.microtime(true).'-'.random_int(1000, 9999), 'is_published' => 1, 'lang' => 'en', 'headers' => '[]',
            'read_count' => 0, 'sent_count' => 0, 'revision' => 1, 'variant_read_count' => 0, 'variant_sent_count' => 0];

        if ('' !== $column) {
            $row[$column] = $parent;
        }

        return $this->insert('emails', $row);
    }

    private function stepUsing(int $emailId, ?string $stepDeleted = null): void
    {
        $campaign = $this->insert('campaigns', ['name' => 'ZZ-family '.microtime(true), 'is_published' => 1, 'allow_restart' => 0]);
        $this->insert('campaign_events', [
            'campaign_id' => $campaign, 'name' => 'step', 'type' => 'n8ndispatch.email.send', 'event_type' => 'action',
            'event_order' => 1, 'properties' => serialize(['email' => $emailId]), 'failed_count' => 0, 'deleted' => $stepDeleted,
        ]);
    }

    public function testAnEmailWhoseTranslationIsUsedCountsAsInUse(): void
    {
        $parent = $this->email();
        $child  = $this->email('translation_parent_id', $parent);
        $other  = $this->email();
        $this->stepUsing($child);

        $this->assertSame([$parent], $this->finder()->inUseAmong([$parent, $other]));
    }

    public function testAnEmailWhoseVariantIsUsedCountsAsInUse(): void
    {
        $parent  = $this->email();
        $variant = $this->email('variant_parent_id', $parent);
        $this->stepUsing($variant);

        $this->assertSame([$parent], $this->finder()->inUseAmong([$parent]));
    }

    public function testTheWholeSubtreeCounts(): void
    {
        $root       = $this->email();
        $child      = $this->email('translation_parent_id', $root);
        $grandchild = $this->email('variant_parent_id', $child);
        $this->stepUsing($grandchild);

        $this->assertSame([$root, $child], $this->finder()->inUseAmong([$root, $child]));
    }

    public function testTheParentOfAnEmailInUseIsNotAffectedByItsChild(): void
    {
        $parent = $this->email();
        $child  = $this->email('translation_parent_id', $parent);
        $this->stepUsing($parent);

        $this->assertSame([$parent], $this->finder()->inUseAmong([$parent, $child]));
    }

    public function testADeletedStepOfADescendantDoesNotCount(): void
    {
        $parent = $this->email();
        $child  = $this->email('translation_parent_id', $parent);
        $this->stepUsing($child, '2026-01-01 00:00:00');

        $this->assertSame([], $this->finder()->inUseAmong([$parent]));
    }

    public function testEmailsThatPointAtEachOtherDoNotLoopForever(): void
    {
        $a = $this->email();
        $b = $this->email('translation_parent_id', $a);
        $this->db->update('emails', ['translation_parent_id' => $b], ['id' => $a]);
        $this->stepUsing($b);

        $this->assertSame([$a], $this->finder()->inUseAmong([$a]));
    }

    public function testNoIdsIsNotInUse(): void
    {
        $this->assertSame([], $this->finder()->inUseAmong([]));
    }
}
