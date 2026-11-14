<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use MauticPlugin\N8nDispatchBundle\Migrations\Version_0_11_0;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[Group('area:alerts')]
#[Group('risk:INFRA')]
class Version_0_11_0Test extends TestCase
{
    public function testItCreatesTheAlertLogTableWithItsColumnsAndIndex(): void
    {
        $sql = $this->runMigration('', new Schema());

        $this->assertCount(1, $sql);
        $this->assertStringStartsWith('CREATE TABLE n8n_dispatch_alert_log (', $sql[0]);
        foreach (['id INT UNSIGNED AUTO_INCREMENT NOT NULL', 'created_at DATETIME NOT NULL', 'alert VARCHAR(191) NOT NULL', 'outcome VARCHAR(191) NOT NULL', 'reason VARCHAR(191) DEFAULT NULL', 'detail LONGTEXT DEFAULT NULL', 'subject VARCHAR(191) DEFAULT NULL', 'INDEX n8n_dispatch_alert_log_created (created_at)', 'PRIMARY KEY(id)'] as $part) {
            $this->assertStringContainsString($part, $sql[0]);
        }
    }

    public function testItUsesTheTablePrefix(): void
    {
        $sql = $this->runMigration('mtc_', new Schema());

        $this->assertStringStartsWith('CREATE TABLE mtc_n8n_dispatch_alert_log (', $sql[0]);
        $this->assertStringContainsString('INDEX mtc_n8n_dispatch_alert_log_created (created_at)', $sql[0]);
    }

    public function testItDoesNothingWhenTheTableExists(): void
    {
        $schema = new Schema();
        $schema->createTable('n8n_dispatch_alert_log')->addColumn('id', Types::INTEGER);

        $this->assertSame([], $this->runMigration('', $schema));
    }

    /**
     * @return list<string>
     */
    private function runMigration(string $prefix, Schema $schema): array
    {
        $executed      = [];
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn($schema);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('prepare')->willReturnCallback(function (string $sql) use (&$executed) {
            $executed[] = trim((string) preg_replace('/\s+/', ' ', $sql));

            return $this->createMock(Statement::class);
        });

        $entityManager = $this->createStub(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $migration = new Version_0_11_0($entityManager, $prefix);

        if ($migration->shouldExecute()) {
            $migration->execute();
        }

        return $executed;
    }
}
