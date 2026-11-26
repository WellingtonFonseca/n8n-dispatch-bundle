<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\PluginBundle\Entity\Plugin;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariables;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\N8nDispatchBundle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade to the per-Email "Enviar por": the column is added, and an instance that had the old instance-wide
 * 'email_send_mode' on 'mautic' keeps its existing emails on Mautic instead of flipping them to n8n.
 */
#[Group('area:email')]
#[Group('area:dispatch-email')]
#[Group('risk:DADOS')]
class EmailProviderUpgradeTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', '');
        }
        $this->connection = $this->createMock(Connection::class);
    }

    private function schema(bool $withProvider): Schema
    {
        $schema = new Schema();
        $table  = $schema->createTable(EmailVariables::TABLE_NAME);
        $table->addColumn('id', 'integer');
        $table->addColumn('email_id', 'integer');
        if ($withProvider) {
            $table->addColumn('provider', 'string');
        }

        return $schema;
    }

    private function upgrade(Schema $schema, ?string $oldMode): void
    {
        $integration = $this->createMock(N8nDispatchIntegration::class);
        $integration->method('getKeys')->willReturn(null === $oldMode ? [] : ['email_send_mode' => $oldMode]);
        $helper = $this->createMock(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->with(N8nDispatchIntegration::NAME)->willReturn($integration);
        $factory = $this->createMock(MauticFactory::class);
        $factory->method('getDatabase')->willReturn($this->connection);
        $factory->method('getHelper')->with('integration')->willReturn($helper);

        N8nDispatchBundle::onPluginUpdate(new Plugin(), $factory, [], $schema);
    }

    public function testAnOldMauticInstanceKeepsItsEmailsOnMautic(): void
    {
        $this->connection->expects($this->once())->method('executeQuery')
            ->with($this->stringContains('ADD COLUMN provider'));
        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$statements): int {
            $statements[] = [$sql, $params];

            return 0;
        });

        $this->upgrade($this->schema(false), 'mautic');

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('UPDATE '.EmailVariables::TABLE_NAME.' SET provider', $statements[0][0]);
        $this->assertStringContainsString('INSERT INTO '.EmailVariables::TABLE_NAME, $statements[1][0], 'emails with no row yet get one');
        $this->assertSame(['p' => EmailVariables::PROVIDER_MAUTIC], $statements[0][1]);
        $this->assertSame(['p' => EmailVariables::PROVIDER_MAUTIC], $statements[1][1]);
    }

    public function testAnOldN8nInstanceOnlyGetsTheColumn(): void
    {
        $this->connection->expects($this->once())->method('executeQuery')->with($this->stringContains("DEFAULT 'n8n'"));
        $this->connection->expects($this->never())->method('executeStatement');

        $this->upgrade($this->schema(false), 'n8n');
    }

    public function testAnInstanceWithNoSavedModeOnlyGetsTheColumn(): void
    {
        $this->connection->expects($this->once())->method('executeQuery');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->upgrade($this->schema(false), null);
    }

    public function testAnAlreadyUpgradedTableIsLeftAlone(): void
    {
        $this->connection->expects($this->never())->method('executeQuery');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->upgrade($this->schema(true), 'mautic');
    }
}
