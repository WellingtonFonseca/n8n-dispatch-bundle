<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Base of the tests that run against the stack's real MySQL: a connection that
 * returns every value as text (so the casts of the code under test are the ones
 * that make ints), and a transaction rolled back after each test, also when it
 * fails, so a test leaves nothing behind and reads no seed.
 */
abstract class RollbackDatabaseTestCase extends TestCase
{
    protected Connection $db;

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
     * @param array<string, mixed> $row
     */
    protected function insert(string $table, array $row): int
    {
        $this->db->insert($table, $row);

        return (int) $this->db->lastInsertId();
    }
}
