<?php

namespace Genaker\Bundle\LocalIntegrationTests\Util;

use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for integration tests using HttpKernel strategy.
 *
 * - Boot kernel in 'dev' environment (not 'test')
 * - Use real application config and database
 * - Make HTTP requests using Symfony's Request/Response directly
 * - No test.client overhead
 * - Provides database helpers via DatabaseTestTrait
 * - Detects HTTP scheme mismatches between ORO_TEST_HTTP_SCHEME and oro_config_value
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
abstract class IntegrationTestCase extends KernelTestCase // NOSONAR - S3776: Complex base test class
{
    use DatabaseTestTrait;

    private static ?string $schemeMismatchError = null;

    private static bool $schemeChecked = false;

    private int $initialObLevel = 0;

    protected static function getKernelClass(): string
    {
        return \AppKernel::class;
    }

    protected function setUp(): void
    {
        if (getenv('INTEGRATION_TESTS_ENABLED') !== '1') {
            $this->markTestSkipped('Integration tests disabled (set INTEGRATION_TESTS_ENABLED=1 to enable).');
        }

        if (getenv('ORO_DB_URL') === false || getenv('ORO_DB_URL') === '') {
            $this->markTestSkipped('Integration tests require ORO_DB_URL to be set.');
        }

        if (!$this->isDatabaseSynced()) {
            $this->markTestSkipped('Database schema not synced. Run: php bin/console doctrine:schema:update --force');
        }

        $this->initialObLevel = ob_get_level();
        parent::setUp();

        ob_start();

        $options = ['environment' => 'dev', 'debug' => false];

        if (!static::$booted) {
            putenv('ORO_LOCALE_LANGUAGE=en');
            ini_set('intl.default_locale', 'en_US');

            static::bootKernel($options);
        }

        try {
            $this->initDbFromContainer();
        } catch (\Exception $e) {
            $this->markTestSkipped('Database unreachable: ' . $e->getMessage());
        }

        $this->assertNoSchemeMismatch();
    }

    /**
     * Fetch the application URL stored in oro_config_value, or null when the DB is
     * absent / unreachable / has no matching row.
     *
     * Extracted as a protected method so test subclasses can override it and return
     * a controlled value without needing a live database connection.
     */
    protected function fetchConfiguredUrl(): ?string
    {
        $rawDbUrl = (string) getenv('ORO_DB_URL');
        if ($rawDbUrl === '') {
            return null;
        }

        try {
            $conn = DriverManager::getConnection([
                'url' => str_replace('postgres://', 'pgsql://', $rawDbUrl),
            ]);
            $row = $conn->executeQuery(
                "SELECT text_value FROM oro_config_value WHERE name = 'url' ORDER BY id LIMIT 1"
            )->fetchAssociative();

            return $row ? (string) $row['text_value'] : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Detect a scheme mismatch between ORO_TEST_HTTP_SCHEME and the URL stored in
     * oro_config_value (queried once per process and cached statically).
     *
     * A mismatch causes every test to receive a redirect instead of JSON, so we
     * report it immediately with the exact SQL needed to fix it.
     */
    protected function assertNoSchemeMismatch(): void
    {
        if (self::$schemeChecked) {
            if (self::$schemeMismatchError !== null) {
                $this->fail(self::$schemeMismatchError);
            }
            return;
        }
        self::$schemeChecked = true;

        $expected  = getenv('ORO_TEST_HTTP_SCHEME') ?: 'http';
        $configUrl = $this->fetchConfiguredUrl();

        if ($configUrl === null || !preg_match('#^(https?)://#', $configUrl, $m)) {
            return;
        }

        $actual = $m[1];
        if ($actual === $expected) {
            return;
        }

        self::$schemeMismatchError = sprintf(
            "\n" .
            "=== SCHEME MISMATCH — all integration tests will fail until fixed ===\n" .
            "  DB oro_config_value.url uses : %s://\n" .
            "  ORO_TEST_HTTP_SCHEME expects : %s://\n" .
            "\n" .
            "Fix option A — update the DB to match the test config:\n" .
            "  PGPASSWORD=oro_db_pass psql -h pgsql -U oro_db_user -d oro_db_uat -c \"" .
            "UPDATE oro_config_value SET text_value = REPLACE(text_value, '%s://', '%s://') " .
            "WHERE name IN ('url','secure_url','application_url');\"\n" .
            "  Then clear cache: rm -rf var/cache/dev\n" .
            "\n" .
            "Fix option B — update phpunit-shippingcart.xml ORO_TEST_HTTP_SCHEME to '%s'\n",
            $actual,
            $expected,
            $actual,
            $expected,
            $actual
        );
        $this->fail(self::$schemeMismatchError);
    }

    protected function request(
        string $method,
        string $uri,
        array $server = [],
        array $content = []
    ): Response {
        if (str_starts_with($uri, '/') && !str_starts_with($uri, '//')) {
            $config = $this->getTestHttpConfig();
            $uri = $config['scheme'] . '://' . $config['host'] . $uri;
        }

        $defaultServer = [
            'HTTP_ACCEPT' => 'application/json, application/xml;q=0.9, */*;q=0.8',
        ];
        $server = array_merge($defaultServer, $server);

        $sessionId   = bin2hex(random_bytes(16));
        $sessionName = ini_get('session.name') ?: 'PHPSESSID';

        $request = Request::create(
            $uri,
            $method,
            $content,
            [$sessionName => $sessionId],
            [],
            array_merge($this->defaultServerVars(), $server),
            $content ? json_encode($content) : null
        );

        if (!empty($content)) {
            $request->headers->set('Content-Type', 'application/json');
        }

        $request->headers->set('Accept', 'application/json');

        return $this->dispatchRequest($request);
    }

    /**
     * Dispatch a Request through the kernel, managing output buffers and
     * recovering gracefully from headers-already-sent RuntimeExceptions.
     *
     * Tests that need to build their own Request (e.g. to bypass the Accept-JSON
     * defaults applied by request()) can call this directly instead of
     * $kernel->handle(), which otherwise leaks session-start failures.
     */
    protected function dispatchRequest(Request $request): Response
    {
        try {
            $initialLevel = ob_get_level();
            $response = $this->getKernel()->handle($request);
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
        } catch (\RuntimeException $e) {
            while (ob_get_level() > 1) {
                ob_end_clean();
            }
            if (strpos($e->getMessage(), 'headers have already been sent') !== false) {
                return new Response($e->getMessage(), 503);
            }
            throw $e;
        }

        return $response;
    }

    protected function defaultServerVars(): array
    {
        $config = $this->getTestHttpConfig();

        return [
            'HTTP_HOST'       => $config['host'] . ':' . $config['port'],
            'SERVER_NAME'     => $config['host'],
            'SERVER_PORT'     => $config['port'],
            'HTTPS'           => $config['https'],
            'REQUEST_SCHEME'  => $config['scheme'],
            'SCRIPT_FILENAME' => dirname(__DIR__) . '/../../public/index.php',
            'REQUEST_URI'     => '/',
        ];
    }

    protected function getBaseUrl(): string
    {
        $config = $this->getTestHttpConfig();

        return $config['scheme'] . '://' . $config['host'] . ':' . $config['port'];
    }

    private function getTestHttpConfig(): array
    {
        $scheme = getenv('ORO_TEST_HTTP_SCHEME') ?: 'http';
        $host   = getenv('ORO_TEST_HTTP_HOST') ?: 'localhost';
        $port   = (int) (getenv('ORO_TEST_HTTP_PORT') ?: 8000);

        return [
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => $port,
            'https'  => $scheme === 'https' ? 'on' : 'off',
        ];
    }

    /**
     * Get the DI container.
     *
     * Reuses the kernel booted by setUp() (with environment=dev). Calling
     * bootKernel() with no args would shut down that kernel and re-create one
     * from $_ENV defaults (environment=test), which breaks env-gated services
     * such as dev-only commands that check getEnvironment() === 'dev'.
     */
    protected static function getContainer(): ContainerInterface
    {
        if (!static::$booted || static::$kernel === null) {
            static::bootKernel(['environment' => 'dev', 'debug' => false]);
        }
        return static::$kernel->getContainer();
    }

    protected function getKernel(): \Symfony\Component\HttpKernel\HttpKernelInterface
    {
        return self::$kernel;
    }

    protected function get(string $uri, array $server = []): Response
    {
        return $this->request('GET', $uri, $server);
    }

    protected function post(string $uri, array $content = [], array $server = []): Response
    {
        return $this->request('POST', $uri, $server, $content);
    }

    protected function put(string $uri, array $content = [], array $server = []): Response
    {
        return $this->request('PUT', $uri, $server, $content);
    }

    protected function delete(string $uri, array $server = []): Response
    {
        return $this->request('DELETE', $uri, $server);
    }

    protected function withBasicAuth(string $username, string $password): array
    {
        return [
            'PHP_AUTH_USER' => $username,
            'PHP_AUTH_PW'   => $password,
        ];
    }

    protected function getEnvironment(): string
    {
        return self::$kernel->getEnvironment();
    }

    protected function isDebug(): bool
    {
        return self::$kernel->isDebug();
    }

    /**
     * Check whether key Oro tables exist in the database.
     *
     * Called in setUp() before booting the kernel to skip tests gracefully when
     * the schema has not been synced, avoiding kernel-boot side effects on unit tests.
     */
    protected function isDatabaseSynced(): bool
    {
        $rawDbUrl = (string) getenv('ORO_DB_URL');
        if ($rawDbUrl === '') {
            return true;
        }

        try {
            return $this->checkRequiredTablesExist($rawDbUrl);
        } catch (\Exception) {
            return false;
        }
    }

    private function checkRequiredTablesExist(string $rawDbUrl): bool
    {
        $conn = DriverManager::getConnection([
            'url' => str_replace('postgres://', 'pgsql://', $rawDbUrl),
        ]);

        $tables = ['oro_config_value', 'oro_entity_config', 'oro_entity_config_field'];
        foreach ($tables as $table) {
            $exists = $conn->executeQuery(
                'SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = ?',
                [$table, 'public']
            )->fetchOne();

            if (!$exists) {
                return false;
            }
        }
        return true;
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }
}
