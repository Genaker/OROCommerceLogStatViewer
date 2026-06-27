<?php

namespace Genaker\Bundle\LocalIntegrationTests\Util;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Generic DBAL helpers for integration tests.
 *
 * Provides two connection bootstrap methods so the same query helpers work in
 * both kernel-dispatched tests (IntegrationTestCase) and real-HTTP tests
 * (HttpTestCase), where no Symfony container is available:
 *
 *   - initDbFromContainer() — for IntegrationTestCase / KernelTestCase subclasses
 *   - initDbFromEnv()       — for HttpTestCase / plain TestCase subclasses
 *
 * Usage
 * Call the appropriate init method in your setUp(), then use dbQuery(),
 * dbFetchOne(), and dbExecute() instead of raw PDO / inline DBAL calls.
 *
 * @phpcs:disable Generic.Metrics.LineLength.TooLong
 */
trait DatabaseTestTrait
{
    protected Connection $db;

    /** True when $db was created by initDbFromEnv() and must be closed in teardown. */
    private bool $ownedDbConnection = false;

    /** False when ORO_DB_URL is missing or connection fails (graceful degradation for CI without DB). */
    private bool $dbAvailable = true;

    // Connection bootstrap

    /**
     * Initialise $db from the Symfony DI container.
     *
     * Requires the using class to be a KernelTestCase (or IntegrationTestCase)
     * subclass so that static::getContainer() is available.
     *
     * @throws \Exception propagated so callers can markTestSkipped on failure.
     */
    protected function initDbFromContainer(): void
    {
        /** @var Connection $db */
        $db       = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        $this->db->executeQuery('SELECT 1'); // ping — throws on connection failure
    }

    /**
     * Initialise $db from an env-var Postgres URL (no container needed).
     *
     * Used by HttpTestCase-based tests where the kernel is not available.
     * The env var must follow the format:
     *   postgres://user:pass@host:port/dbname
     *
     * Graceful degradation for CI environments:
     *   - If INTEGRATION_TESTS_ENABLED != '1': sets $dbAvailable = false (tests skip automatically)
     *   - If ORO_DB_URL is missing/empty: sets $dbAvailable = false (tests skip automatically)
     *   - If connection fails: sets $dbAvailable = false and propagates exception
     *
     * @throws \Exception on actual connection failure (propagated so callers can markTestSkipped).
     */
    protected function initDbFromEnv(string $envKey = 'ORO_DB_URL'): void
    {
        // Skip if integration tests are explicitly disabled
        if ((string) getenv('INTEGRATION_TESTS_ENABLED') !== '1') {
            $this->dbAvailable = false;
            return;
        }

        $raw = (string) getenv($envKey);
        if ($raw === '') {
            $this->dbAvailable = false;
            return;
        }
        // DBAL 3 accepts 'pgsql://' or 'postgresql://'; env var may use 'postgres://'
        $url = str_replace('postgres://', 'pgsql://', $raw);
        try {
            $this->db = DriverManager::getConnection(['url' => $url]);
            $this->db->executeQuery('SELECT 1'); // ping
            $this->ownedDbConnection = true;
        } catch (\Exception $e) {
            $this->dbAvailable = false;
            throw $e; // propagate connection failures
        }
    }

    /**
     * Skip the current test if the database is not available.
     *
     * Call this early in setUp() or at the start of a test method that requires the DB.
     * Gracefully handles CI environments where:
     *   - INTEGRATION_TESTS_ENABLED is not set to '1'
     *   - ORO_DB_URL is not set or connection fails
     *
     * Note: Requires the calling class to be a TestCase subclass with markTestSkipped() method.
     */
    protected function skipIfDbNotAvailable(): void
    {
        if (!$this->dbAvailable) {
            if (method_exists($this, 'markTestSkipped')) {
                $this->markTestSkipped(
                    'Database not available (INTEGRATION_TESTS_ENABLED not set or ORO_DB_URL missing)'
                );
            }
        }
    }

    // Query helpers

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function dbQuery(string $sql, array $params = []): array
    {
        $this->skipIfDbNotAvailable();
        return $this->db->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Execute a SELECT and return the first row as an associative array, or null if empty.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function dbFetchRow(string $sql, array $params = []): ?array
    {
        $this->skipIfDbNotAvailable();
        $result = $this->db->executeQuery($sql, $params)->fetchAssociative();
        return $result === false ? null : $result;
    }

    /**
     * Execute a SELECT and return the first column of the first row.
     *
     * Returns false when the result set is empty (consistent with DBAL).
     *
     * @param array<int|string, mixed> $params
     */
    protected function dbFetchOne(string $sql, array $params = []): mixed
    {
        $this->skipIfDbNotAvailable();
        return $this->db->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * Execute an INSERT / UPDATE / DELETE and return the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    protected function dbExecute(string $sql, array $params = []): int
    {
        $this->skipIfDbNotAvailable();
        return (int) $this->db->executeStatement($sql, $params);
    }

    /**
     * Close the DBAL connection after each test to avoid exhausting PostgreSQL's
     * max_connections limit when the full suite runs.
     *
     * Only closes connections owned by this trait (created via initDbFromEnv()).
     * Connections borrowed from the Symfony container (initDbFromContainer()) are
     * shared and must not be closed here.
     */
    protected function tearDownDatabaseConnection(): void
    {
        if ($this->ownedDbConnection && isset($this->db) && $this->db->isConnected()) {
            $this->db->close();
        }
        $this->ownedDbConnection = false;
    }
}
