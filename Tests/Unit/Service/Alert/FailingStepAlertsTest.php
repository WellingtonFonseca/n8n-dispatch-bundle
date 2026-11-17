<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use MauticPlugin\N8nDispatchBundle\Entity\StepFailureRepository;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignLink;
use MauticPlugin\N8nDispatchBundle\Service\Alert\FailingStepAlerts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('area:alerts')]
#[Group('risk:CRITICO')]
#[Group('risk:FLUXO')]
class FailingStepAlertsTest extends TestCase
{
    /** @var StepFailureRepository&MockObject */
    private StepFailureRepository $failures;

    /** @var AlertSender&MockObject */
    private AlertSender $sender;

    private FailingStepAlerts $alerts;

    private const STEP = [
        'campaign_id' => 7, 'campaign_name' => 'Boas-vindas', 'event_id' => 21, 'step_name' => 'SMS de cobrança',
        'step_type' => 'n8ndispatch.sms.send', 'contacts' => 12, 'max_failures' => 9, 'oldest' => '2026-11-16 09:30:00',
    ];

    protected function setUp(): void
    {
        $this->failures = $this->createMock(StepFailureRepository::class);
        $this->sender   = $this->createMock(AlertSender::class);
        $settings       = $this->createMock(AlertSettings::class);
        $settings->method('current')->willReturn(AlertSettings::fromArray([AlertSettings::KEY_FAILING_THRESHOLD => 4]));
        $link = $this->createMock(CampaignLink::class);
        $link->method('named')->willReturnCallback(static fn (int $id, ?string $name): array => ['id' => $id, 'name' => $name, 'url' => 'https://mautic.test/s/campaigns/view/'.$id]);
        $this->alerts = new FailingStepAlerts($this->failures, $this->sender, $settings, $link);
    }

    public function testItCleansFirstAndReadsTheStepsWithTheConfiguredThreshold(): void
    {
        $order = [];
        $this->failures->expects($this->once())->method('deleteResolved')->willReturnCallback(function () use (&$order): int {
            $order[] = 'clean';

            return 3;
        });
        $this->failures->expects($this->once())->method('failingSteps')->with(4)->willReturnCallback(function () use (&$order): array {
            $order[] = 'read';

            return [];
        });
        $this->failures->expects($this->once())->method('reasons')->with(4)->willReturn([]);

        $result = $this->alerts->sweep(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('UTC')));

        $this->assertSame(['clean', 'read'], $order, 'a stale row must be gone before the count');
        $this->assertSame(['cleaned' => 3, 'steps' => 0, 'contacts' => 0], $result);
    }

    public function testNothingIsSentWhenNoContactIsStuck(): void
    {
        $this->failures->method('failingSteps')->willReturn([]);
        $this->failures->method('reasons')->willReturn([]);
        $this->sender->expects($this->never())->method('send');

        $this->alerts->sweep(new \DateTimeImmutable());
    }

    public function testOneAlertPerStuckStepCarriesTheNumbersTheLinkAndEveryReason(): void
    {
        $other = ['campaign_id' => 8, 'campaign_name' => 'Cobrança', 'event_id' => 30, 'step_name' => 'Email 2', 'step_type' => 'n8ndispatch.email.send', 'contacts' => 1, 'max_failures' => 4, 'oldest' => '2026-11-16 11:59:00'];
        $this->failures->method('failingSteps')->willReturn([self::STEP, $other]);
        $this->failures->method('reasons')->willReturn([
            ['event_id' => 21, 'reason' => 'HTTP 500', 'contacts' => 8],
            ['event_id' => 21, 'reason' => 'no phone', 'contacts' => 3],
            ['event_id' => 21, 'reason' => 'timeout', 'contacts' => 1],
            ['event_id' => 21, 'reason' => 'fourth reason', 'contacts' => 1],
            ['event_id' => 30, 'reason' => 'template deleted', 'contacts' => 1],
        ]);
        $sent = [];
        $this->sender->expects($this->exactly(2))->method('send')->willReturnCallback(function (string $type, array $data) use (&$sent): bool {
            $sent[] = [$type, $data];

            return true;
        });

        $result = $this->alerts->sweep(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('UTC')));

        $this->assertSame(['cleaned' => 0, 'steps' => 2, 'contacts' => 13], $result);
        $this->assertSame('campaign.step_failing', $sent[0][0]);
        $this->assertSame([
            'campaign'             => ['id' => 7, 'name' => 'Boas-vindas', 'url' => 'https://mautic.test/s/campaigns/view/7'],
            'step'                 => ['id' => 21, 'name' => 'SMS de cobrança', 'type' => 'n8ndispatch.sms.send'],
            'failing_contacts'     => 12,
            'threshold'            => 4,
            'max_failures'         => 9,
            'oldest_failure_at'    => '2026-11-16T09:30:00+00:00',
            'oldest_failure_hours' => 2,
            'reasons'              => [['reason' => 'HTTP 500', 'contacts' => 8], ['reason' => 'no phone', 'contacts' => 3], ['reason' => 'timeout', 'contacts' => 1], ['reason' => 'fourth reason', 'contacts' => 1]],
        ], $sent[0][1]);
        $this->assertSame([['reason' => 'template deleted', 'contacts' => 1]], $sent[1][1]['reasons']);
        $this->assertSame(0, $sent[1][1]['oldest_failure_hours']);
    }

    public function testItSendsWithNoCooldownKeySoTheAlertRepeatsOnEveryRun(): void
    {
        $this->failures->method('failingSteps')->willReturn([self::STEP]);
        $this->failures->method('reasons')->willReturn([]);
        $this->sender->expects($this->once())->method('send')->with('campaign.step_failing', $this->anything(), null, 0)->willReturn(true);

        $this->alerts->sweep(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('UTC')));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function oldestFailures(): iterable
    {
        yield 'one second short of an hour' => ['2026-11-16 10:00:01', 0];
        yield 'exactly one hour' => ['2026-11-16 10:00:00', 1];
        yield 'one hour and a second' => ['2026-11-16 09:59:59', 1];
        yield 'a failure dated in the future (clock ahead) is never negative' => ['2026-11-16 12:00:00', 0];
    }

    /**
     * @dataProvider oldestFailures
     */
    public function testTheAgeOfTheOldestFailureIsInWholeHoursAndNeverNegative(string $oldest, int $hours): void
    {
        $this->failures->method('failingSteps')->willReturn([['oldest' => $oldest] + self::STEP]);
        $this->failures->method('reasons')->willReturn([]);
        $sent = null;
        $this->sender->method('send')->willReturnCallback(function (string $type, array $data) use (&$sent): bool {
            $sent = $data;

            return true;
        });

        $this->alerts->sweep(new \DateTimeImmutable('2026-11-16 11:00:00', new \DateTimeZone('UTC')));

        $this->assertSame($hours, $sent['oldest_failure_hours']);
    }
}
