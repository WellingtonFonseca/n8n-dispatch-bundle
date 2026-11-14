<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\LastRunReport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:plugin-settings')]
#[Group('risk:FLUXO')]
#[Group('risk:DADOS')]
class LastRunReportTest extends TestCase
{
    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-04 03:29:00', new \DateTimeZone('UTC'));
    }

    public function testTheStatusPollBlockHasOneObjectPerChannel(): void
    {
        $json = LastRunReport::json(
            PollRun::KIND_STATUS_POLL,
            $this->at(),
            'OK',
            "email: asked 1, changed 1, unchanged 0, unknown 0, invalid 0, 1 calls\nsms: asked 0, changed 0, unchanged 0, unknown 0, invalid 0, 0 calls",
            null
        );

        $this->assertSame([
            'date'   => '2026-10-04 03:29 UTC',
            'status' => 'OK',
            'email'  => ['asked' => 1, 'changed' => 1, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'calls' => 1],
            'sms'    => ['asked' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'calls' => 0],
        ], json_decode($json, true));
        $this->assertStringContainsString("\n    \"email\": {", $json, 'written out, one key per line');
    }

    public function testTheMirrorResyncBlockHasOneFieldPerCount(): void
    {
        $json = LastRunReport::json(PollRun::KIND_MIRROR_RESYNC, $this->at(), 'Erro', 'due 5, calls 4, ok 3, failed 1, skipped 0, left 2; error: HTTP 500', null);

        $this->assertSame([
            'date'    => '2026-10-04 03:29 UTC',
            'status'  => 'Erro',
            'due'     => 5,
            'calls'   => 4,
            'ok'      => 3,
            'failed'  => 1,
            'skipped' => 0,
            'left'    => 2,
            'error'   => 'HTTP 500',
        ], json_decode($json, true));
    }

    public function testTheSummaryOfAKindIsNotReadByTheOtherKind(): void
    {
        $line = 'due 5, calls 4, ok 3, failed 1, skipped 0, left 2';

        $this->assertSame($line, json_decode(LastRunReport::json(PollRun::KIND_STATUS_POLL, $this->at(), 'OK', $line, null), true)['message']);
    }

    public function testARunWithNoSummaryHasOnlyTheDateAndTheStatus(): void
    {
        foreach ([PollRun::KIND_STATUS_POLL, PollRun::KIND_MIRROR_RESYNC] as $kind) {
            $json = LastRunReport::json($kind, $this->at(), 'Rodando', null, null);

            $this->assertSame(['date' => '2026-10-04 03:29 UTC', 'status' => 'Rodando'], json_decode($json, true));
        }
    }

    public function testAStaleRunCarriesTheWarningRightAfterTheStatus(): void
    {
        $json = LastRunReport::json(PollRun::KIND_MIRROR_RESYNC, $this->at(), 'OK', 'due 0, calls 0, ok 0, failed 0, skipped 0, left 0', 'ATENÇÃO: sem rodadas há muito tempo.');

        $this->assertSame(['date', 'status', 'warning', 'due', 'calls', 'ok', 'failed', 'skipped', 'left'], array_keys(json_decode($json, true)));
        $this->assertStringContainsString('ATENÇÃO', $json, 'unicode is not escaped');
    }

    public function testTheDateIsWrittenInUtc(): void
    {
        $json = LastRunReport::json(PollRun::KIND_STATUS_POLL, new \DateTimeImmutable('2026-10-04 00:29:00', new \DateTimeZone('America/Sao_Paulo')), 'OK', null, null);

        $this->assertSame('2026-10-04 03:29 UTC', json_decode($json, true)['date']);
    }

    public function testAnEmptyWarningIsLeftOut(): void
    {
        $this->assertArrayNotHasKey('warning', json_decode(LastRunReport::json(PollRun::KIND_STATUS_POLL, $this->at(), 'OK', null, ''), true));
    }
}
