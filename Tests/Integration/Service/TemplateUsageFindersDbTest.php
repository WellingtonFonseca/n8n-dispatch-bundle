<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;
use PHPUnit\Framework\TestCase;

/**
 * The query of the three template usage finders against the stack's real
 * MySQL: which campaign steps point at a template, in what order, and that
 * deleted steps and campaigns do not count. Rows are made by the test inside
 * a transaction rolled back at the end.
 */
class TemplateUsageFindersDbTest extends TestCase
{
    private Connection $db;
    private string $unique;

    protected function setUp(): void
    {
        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', '');
        }

        $host = getenv('MAUTIC_DB_HOST');
        if (false === $host || '' === $host) {
            $this->markTestSkipped('Needs the stack database (MAUTIC_DB_* variables of the web container).');
        }

        $this->db = DriverManager::getConnection([
            'driver'        => 'pdo_mysql',
            'host'          => $host,
            'port'          => (int) (getenv('MAUTIC_DB_PORT') ?: 3306),
            'user'          => (string) getenv('MAUTIC_DB_USER'),
            'password'      => (string) getenv('MAUTIC_DB_PASSWORD'),
            'dbname'        => (string) getenv('MAUTIC_DB_DATABASE'),
            'charset'       => 'utf8mb4',
            'driverOptions' => [\PDO::ATTR_STRINGIFY_FETCHES => true],
        ]);
        $this->db->beginTransaction();

        $this->unique = 'ZZ-usage-'.microtime(true).'-'.random_int(1000, 9999);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * @return array<string, array{string, string, string}> finder class, event type, properties key of the template
     */
    public static function finders(): array
    {
        return [
            'email' => [EmailTemplateUsageFinder::class, 'n8ndispatch.email.send', 'email'],
            'sms'   => [SmsTemplateUsageFinder::class, 'n8ndispatch.sms.send', 'smsTemplate'],
            'hsm'   => [HsmTemplateUsageFinder::class, 'n8ndispatch.hsm.send', 'hsmTemplateId'],
        ];
    }

    private function finder(string $class): object
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);

        return new $class($em);
    }

    private function campaign(string $name, int $published = 1, ?string $deleted = null): int
    {
        $this->db->insert('campaigns', ['name' => $this->unique.' '.$name, 'is_published' => $published, 'allow_restart' => 0, 'deleted' => $deleted]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function step(int $campaignId, string $type, string $name, array $properties, ?string $deleted = null): int
    {
        $this->db->insert('campaign_events', [
            'campaign_id' => $campaignId, 'name' => $name, 'type' => $type, 'event_type' => 'action',
            'event_order' => 1, 'properties' => serialize($properties), 'failed_count' => 0, 'deleted' => $deleted,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @dataProvider finders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('finders')]
    public function testTheStepsOfTheTemplateComeByCampaignNameThenStepWithTheirData(string $class, string $type, string $key): void
    {
        $template = random_int(900000000, 999999999);
        $zeta     = $this->campaign('zeta', 0);
        $alpha    = $this->campaign('alpha');
        $second   = $this->step($zeta, $type, 'Z step', [$key => $template, 'status' => 'paused']);
        $third    = $this->step($alpha, $type, 'A step 2', [$key => (string) $template]);
        $first    = $this->step($alpha, $type, 'A step 1', [$key => $template, 'status' => 'production']);
        // Not ours: another template, and a step of another channel with the same id.
        $this->step($alpha, $type, 'other template', [$key => $template + 1]);
        $this->step($alpha, 'n8ndispatch.other.send', 'other type', [$key => $template]);

        $usages = $this->finder($class)->findUsages($template);

        $this->assertSame([
            ['campaignId' => $alpha, 'campaignName' => $this->unique.' alpha', 'campaignPublished' => true, 'eventId' => $third, 'eventName' => 'A step 2', 'status' => 'test'],
            ['campaignId' => $alpha, 'campaignName' => $this->unique.' alpha', 'campaignPublished' => true, 'eventId' => $first, 'eventName' => 'A step 1', 'status' => 'production'],
            ['campaignId' => $zeta, 'campaignName' => $this->unique.' zeta', 'campaignPublished' => false, 'eventId' => $second, 'eventName' => 'Z step', 'status' => 'paused'],
        ], $usages);
    }

    /**
     * @dataProvider finders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('finders')]
    public function testDeletedStepsAndDeletedCampaignsDoNotCount(string $class, string $type, string $key): void
    {
        $template = random_int(900000000, 999999999);
        $live     = $this->campaign('live');
        $gone     = $this->campaign('gone', 1, '2026-01-01 00:00:00');
        $this->step($live, $type, 'deleted step', [$key => $template], '2026-01-01 00:00:00');
        $this->step($gone, $type, 'step of deleted campaign', [$key => $template]);

        $this->assertSame([], $this->finder($class)->findUsages($template));

        $kept = $this->step($live, $type, 'kept', [$key => $template]);
        $this->assertSame([$kept], array_column($this->finder($class)->findUsages($template), 'eventId'));
    }

    public function testTheSmsAndHsmFindersTellWhetherATemplateIsInUse(): void
    {
        foreach ([SmsTemplateUsageFinder::class => ['n8ndispatch.sms.send', 'smsTemplate'], HsmTemplateUsageFinder::class => ['n8ndispatch.hsm.send', 'hsmTemplateId']] as $class => [$type, $key]) {
            $template = random_int(900000000, 999999999);
            $finder   = $this->finder($class);

            $this->assertFalse($finder->isInUse($template));

            $this->step($this->campaign($type), $type, 'step', [$key => $template]);

            $this->assertTrue($finder->isInUse($template));
        }
    }
}
