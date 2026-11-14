<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use MauticPlugin\N8nDispatchBundle\Migrations\Version_0_14_0;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[Group('area:mirror')]
#[Group('area:status-poll')]
#[Group('risk:INFRA')]
class Version_0_14_0Test extends TestCase
{
    private function schema(bool $syncTable, bool $kindColumn): Schema
    {
        $schema = new Schema();
        $runs   = $schema->createTable('n8n_dispatch_poll_run');
        $runs->addColumn('id', Types::INTEGER);
        if ($kindColumn) {
            $runs->addColumn('kind', Types::STRING);
        }
        if ($syncTable) {
            $schema->createTable('n8n_dispatch_mirror_sync')->addColumn('email_id', Types::INTEGER);
        }

        return $schema;
    }

    public function testItCreatesTheSyncTableAndAddsTheKindOfARun(): void
    {
        $sql = $this->runMigration('', $this->schema(false, false));

        $this->assertCount(2, $sql);
        $this->assertStringStartsWith('CREATE TABLE n8n_dispatch_mirror_sync (', $sql[0]);
        foreach (['email_id INT NOT NULL', 'status VARCHAR(191) NOT NULL', 'content_hash VARCHAR(191) NOT NULL', 'http_status INT DEFAULT NULL', 'error LONGTEXT DEFAULT NULL', 'attempts INT NOT NULL', 'source VARCHAR(191) NOT NULL', 'attempted_at DATETIME NOT NULL', 'synced_at DATETIME DEFAULT NULL', 'PRIMARY KEY(email_id)'] as $part) {
            $this->assertStringContainsString($part, $sql[0]);
        }
        $this->assertSame("ALTER TABLE n8n_dispatch_poll_run ADD COLUMN kind VARCHAR(191) NOT NULL DEFAULT 'status_poll'", $sql[1]);
    }

    public function testItUsesTheTablePrefix(): void
    {
        $schema = new Schema();
        $schema->createTable('mtc_n8n_dispatch_poll_run')->addColumn('id', Types::INTEGER);

        $sql = $this->runMigration('mtc_', $schema);

        $this->assertStringStartsWith('CREATE TABLE mtc_n8n_dispatch_mirror_sync (', $sql[0]);
        $this->assertStringStartsWith('ALTER TABLE mtc_n8n_dispatch_poll_run ', $sql[1]);
    }

    public function testEachPartRunsOnlyWhenMissing(): void
    {
        $this->assertSame([], $this->runMigration('', $this->schema(true, true)));
        $this->assertCount(1, $this->runMigration('', $this->schema(true, false)));
        $this->assertStringStartsWith('CREATE TABLE', $this->runMigration('', $this->schema(false, true))[0] ?? '');
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

        $migration = new Version_0_14_0($entityManager, $prefix);

        if ($migration->shouldExecute()) {
            $migration->execute();
        }

        return $executed;
    }
}
