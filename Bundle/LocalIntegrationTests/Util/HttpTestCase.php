<?php

// phpcs:ignoreFile -- Test utility file: long integration test setup lines

namespace Genaker\Bundle\LocalIntegrationTests\Util;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for HTTP integration tests via real cURL requests to the running Symfony dev server.
 *
 * Responsibilities (HTTP concerns only)
 * - Cookie-jar lifecycle per test (setUp / tearDown).
 * - Typed HTTP verbs: get(), post(), put(), delete().
 * - Oro frontend login flow: login().
 * - Low-level doRequest() cURL driver with redirect-following and
 *   automatic markTestSkipped() when the server is unreachable.
 *
 * Database helpers are NOT part of this class. Use DatabaseTestTrait (generic)
 * or ShippingCartFixtureTrait (domain-specific) in your test class instead.
 *
 * Configuration
 * Set env vars to point at a non-default server:
 *   ORO_TEST_HTTP_SCHEME  (default: https)
 *   ORO_TEST_HTTP_HOST    (default: localhost)
 *   ORO_TEST_HTTP_PORT    (default: 8000)
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class HttpTestCase extends TestCase
{
    /** Base URL built from env vars in setUp(). */
    protected string $baseUrl;

    /** Path to the temporary Netscape cookie-jar file for this test. */
    protected string $cookieFile;

    protected function isSslVerifyEnabled(): bool
    {
        return filter_var(getenv('ORO_TEST_SSL_VERIFY') ?: '0', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Shared DBAL connection, lazily bootstrapped once per process from ORO_DB_URL.
     * Mirrors the same URL loaded by Symfony's bootEnv() in the bootstrap file.
     */
    private static ?\Doctrine\DBAL\Connection $sharedConnection = null;

    /** Cached result of the server reachability check — avoids repeated curl calls. */
    private static ?bool $serverReachable = null;

    /**
     * Per-class shared authentication sessions for loginOnce().
     *
     * @var array<string, array{file?: string, failure?: string}>
     */
    private static array $sharedAuthSessions = [];

    private static ?string $schemeMismatchError = null;

    private static bool $schemeChecked = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip integration tests in CI or when server is unreachable (not installed locally).
        // Set INTEGRATION_TESTS_ENABLED=1 (e.g. via phpunit-shippingcart.xml.dist) to force-run
        // regardless of CI env vars or the curl reachability probe.
        if (!getenv('INTEGRATION_TESTS_ENABLED') && ($this->isRunningInCi() || !$this->isServerReachable())) {
            $this->markTestSkipped(
                'Integration tests require a running dev server at https://127.0.0.1:8000. '
                . 'Skipping on CI or when server not available.'
            );
        }

        // Skip if ORO_TEST_FRONTEND_PASSWORD is not set (test fixture dependency)
        if (!getenv('ORO_TEST_FRONTEND_PASSWORD')) {
            $this->markTestSkipped(
                'Integration tests require ORO_TEST_FRONTEND_PASSWORD env var. '
                . 'This is set in phpunit-shippingcart.xml.dist.'
            );
        }

        $scheme        = getenv('ORO_TEST_HTTP_SCHEME') ?: 'https';
        $host          = getenv('ORO_TEST_HTTP_HOST') ?: '127.0.0.1';
        $port          = getenv('ORO_TEST_HTTP_PORT') ?: '8000';
        $this->baseUrl = "{$scheme}://{$host}:{$port}";
        $this->cookieFile = sys_get_temp_dir() . '/oro_test_cookies_' . uniqid('', true) . '.txt';

        $this->assertNoSchemeMismatch();
    }

    /**
     * Fetch the application URL stored in oro_config_value, or null when the DB is
     * absent / unreachable / has no matching row.
     */
    protected function fetchConfiguredUrl(): ?string
    {
        $conn = self::getConnection();
        if ($conn === null) {
            return null;
        }

        try {
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
    private function assertNoSchemeMismatch(): void
    {
        if (self::$schemeChecked) {
            if (self::$schemeMismatchError !== null) {
                $this->fail(self::$schemeMismatchError);
            }
            return;
        }
        self::$schemeChecked = true;

        $expected  = getenv('ORO_TEST_HTTP_SCHEME') ?: 'https';
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

    /**
     * Log in ONCE per test class, then share the authenticated session with every
     * subsequent test by copying the cookie jar into the per-test $this->cookieFile.
     *
     * Call this in setUp() instead of login() when all tests in the class authenticate
     * as the same user. The very first call performs the real login (GET login page +
     * POST credentials = 2 HTTP round-trips); all subsequent calls skip the login and
     * simply copy the already-authenticated jar into the freshly-created per-test file.
     *
     * If login fails, this test and all remaining tests in the class fail with a clear message.
     *
     * Pair with clearSharedAuthSession() in tearDownAfterClass() to clean up the file.
     */
    protected function loginOnce(string $email, string $password): void
    {
        $class = static::class;

        if (isset(self::$sharedAuthSessions[$class]['failure'])) {
            $this->fail(self::$sharedAuthSessions[$class]['failure']);
        }

        if (!isset(self::$sharedAuthSessions[$class]['file'])) {
            try {
                $this->login($email, $password);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                $msg = 'Login failed — server may be unreachable: ' . $e->getMessage()
                    . ' — start it with: symfony server:start -d --port=8000 --allow-all-ip --listen-ip=0.0.0.0';
                self::$sharedAuthSessions[$class]['failure'] = $msg;
                $this->fail($msg);
            }

            self::$sharedAuthSessions[$class]['file'] = $this->cookieFile;

            $copy = sys_get_temp_dir() . '/oro_http_shared_' . uniqid('', true) . '.txt';
            copy(self::$sharedAuthSessions[$class]['file'], $copy);
            $this->cookieFile = $copy;
        } else {
            copy(self::$sharedAuthSessions[$class]['file'], $this->cookieFile);
        }
    }

    /**
     * Delete the shared cookie file that loginOnce() created for the calling class
     * and remove its registry entry.
     *
     * Call this in tearDownAfterClass() in every class that uses loginOnce().
     */
    protected static function clearSharedAuthSession(): void
    {
        $class = static::class;
        if (isset(self::$sharedAuthSessions[$class]['file'])) {
            $f = self::$sharedAuthSessions[$class]['file'];
            if (file_exists($f)) {
                unlink($f);
            }
        }
        unset(self::$sharedAuthSessions[$class]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
        parent::tearDown();
    }

    /**
     * Check if running in a CI environment (GitHub Actions, GitLab CI, etc.).
     */
    private function isRunningInCi(): bool
    {
        $ciEnvVars = ['CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'CIRCLECI', 'TRAVIS', 'BUILDKITE'];
        foreach ($ciEnvVars as $var) {
            if (getenv($var)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the dev server is reachable via a quick connection test.
     * Tries HTTP first (common for local dev servers), then HTTPS.
     */
    private function isServerReachable(): bool
    {
        if (self::$serverReachable !== null) {
            return self::$serverReachable;
        }

        $host   = getenv('ORO_TEST_HTTP_HOST') ?: '127.0.0.1';
        $port   = getenv('ORO_TEST_HTTP_PORT') ?: '8000';
        $scheme = getenv('ORO_TEST_HTTP_SCHEME') ?: 'https';

        // Try custom scheme first
        if ($this->testServerConnection("{$scheme}://{$host}:{$port}/")) {
            return self::$serverReachable = true;
        }

        // If custom scheme is not https or http, try both
        if ($scheme !== 'https' && $scheme !== 'http') {
            if ($this->testServerConnection("https://{$host}:{$port}/")) {
                return self::$serverReachable = true;
            }
            if ($this->testServerConnection("http://{$host}:{$port}/")) {
                return self::$serverReachable = true;
            }
        } elseif ($scheme === 'https') {
            // If https failed, try http as fallback
            if ($this->testServerConnection("http://{$host}:{$port}/")) {
                return self::$serverReachable = true;
            }
        }

        return self::$serverReachable = false;
    }

    /**
     * Test connection to a specific URL.
     */
    private function testServerConnection(string $url): bool
    {
        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 5);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        $sslVerify = $this->isSslVerifyEnabled();
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_exec($curlHandle);
        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $errno      = (int) curl_errno($curlHandle);
        curl_close($curlHandle);

        // errno 28 = timeout waiting for body; but if we got a status code the server
        // is alive.  Only reject hard connection failures where status code is 0.
        return $statusCode !== 0;
    }

    protected function get(string $uri, array $headers = []): Response
    {
        return $this->doRequest('GET', $uri, null, $headers);
    }

    /**
     * Return the shared DBAL connection, creating it once from ORO_DB_URL.
     * Returns null when the database is unreachable.
     */
    protected static function getConnection(): ?\Doctrine\DBAL\Connection
    {
        if (self::$sharedConnection !== null) {
            return self::$sharedConnection;
        }
        try {
            $raw = (string) getenv('ORO_DB_URL');
            if ($raw === '') {
                return null;
            }
            // DBAL 3 accepts 'pgsql://' but the env var may use 'postgres://'
            $url = str_replace('postgres://', 'pgsql://', $raw);
            self::$sharedConnection = DriverManager::getConnection(['url' => $url]);
            self::$sharedConnection->executeQuery('SELECT 1'); // ping
        } catch (\Exception) {
            self::$sharedConnection = null;
        }
        return self::$sharedConnection;
    }

    /**
     * Return the first ShippingCart ID from the database, or null if none exist / DB unreachable.
     * Tests that use this method call markTestSkipped() when null is returned.
     */
    protected function getFirstCartId(): ?int
    {
        $conn = self::getConnection();
        if ($conn === null) {
            return null;
        }
        try {
            $row = $conn->executeQuery(
                'SELECT id FROM egerdau_shipping_cart ORDER BY id LIMIT 1'
            )->fetchAssociative();
            return $row ? (int) $row['id'] : null;
        } catch (\Exception) {
            return null;
        }
    }

    protected function post(string $uri, array $content = [], array $headers = []): Response
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        return $this->doRequest('POST', $uri, json_encode($content), $headers);
    }

    protected function put(string $uri, array $content = [], array $headers = []): Response
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        return $this->doRequest('PUT', $uri, json_encode($content), $headers);
    }

    protected function delete(string $uri, array $headers = []): Response
    {
        return $this->doRequest('DELETE', $uri, null, $headers);
    }

    /**
     * Fixed identifier / secret used when auto-provisioning a test OAuth2 client from the DB.
     * The secret is stored sodium-hashed in oro_oauth2_client on first use.
     */
    private const TEST_OAUTH_CLIENT_IDENTIFIER = 'egerdau-test-api-client';
    private const TEST_OAUTH_CLIENT_SECRET     = 'egerdau-test-secret-api-v2!';

    /**
     * Token cache keyed by "clientId:username" (or "__db__" for auto-provisioned path).
     * Avoids one HTTP round-trip to /oauth2-token per test method — the token is valid for 1 h.
     *
     * @var array<string, string>
     */
    private static array $tokenCache = [];

    /**
     * Obtain an OAuth2 Bearer token — never skips, fails the test on real errors.
     *
     * Two paths:
     *   1. All four env vars are set -> password grant with those credentials.
     *      ORO_OAUTH_CLIENT_ID, ORO_OAUTH_CLIENT_SECRET, ORO_ADMIN_USERNAME, ORO_ADMIN_PASSWORD
     *
     *   2. Env vars absent -> auto-provision a test OAuth2 client in the DB
     *      (client_credentials grant owned by the first active admin user).
     *      The client is upserted so repeated runs are safe, and no plaintext
     *      user password is required.
     *
     * The only remaining skip is when the HTTP server is unreachable (handled
     * by doRequest() itself).
     */
    protected function getOAuthBearerToken(): string
    {
        $clientId     = (string) getenv('ORO_OAUTH_CLIENT_ID');
        $clientSecret = (string) getenv('ORO_OAUTH_CLIENT_SECRET');
        $username     = (string) getenv('ORO_ADMIN_USERNAME');
        $password     = (string) getenv('ORO_ADMIN_PASSWORD');

        //  Path 1: explicit env-var credentials (password grant)
        if ($clientId !== '' && $clientSecret !== '' && $username !== '' && $password !== '') {
            $cacheKey = $clientId . ':' . $username;
            if (!isset(self::$tokenCache[$cacheKey])) {
                self::$tokenCache[$cacheKey] = $this->obtainPasswordGrantToken(
                    $clientId,
                    $clientSecret,
                    $username,
                    $password
                );
            }
            return self::$tokenCache[$cacheKey];
        }

        //  Path 2: DB-backed test client (client_credentials grant) ─
        if (!isset(self::$tokenCache['__db__'])) {
            $conn = self::getConnection();
            if ($conn === null) {
                $this->markTestSkipped('Database unreachable; cannot provision test OAuth2 client.');
            }

            $this->provisionTestOAuthClient($conn);

            self::$tokenCache['__db__'] = $this->obtainClientCredentialsToken(
                self::TEST_OAUTH_CLIENT_IDENTIFIER,
                self::TEST_OAUTH_CLIENT_SECRET
            );
        }

        return self::$tokenCache['__db__'];
    }

    /**
     * POST /oauth2-token with password grant and return the access_token string.
     */
    private function obtainPasswordGrantToken(
        string $clientId,
        string $clientSecret,
        string $username,
        string $password
    ): string {
        $body = http_build_query([
            'grant_type'    => 'password',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'username'      => $username,
            'password'      => $password,
        ]);

        $response = $this->doRequest('POST', '/oauth2-token', $body, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->fail(sprintf(
                'OAuth2 password grant returned HTTP %d — check ORO_OAUTH_* / ORO_ADMIN_* env vars. Body: %s',
                $response->getStatusCode(),
                $response->getContent()
            ));
        }

        return $this->extractAccessToken($response->getContent());
    }

    /**
     * Upsert a test OAuth2 `client_credentials` client owned by the first active
     * admin user found in oro_user.  Safe to call multiple times — uses ON CONFLICT.
     *
     * @param \Doctrine\DBAL\Connection $conn
     */
    private function provisionTestOAuthClient(\Doctrine\DBAL\Connection $conn): void
    {
        // Find the first active admin user to act as the token owner.
        $adminRow = $conn->executeQuery(
            "SELECT u.id, u.organization_id
               FROM oro_user u
              WHERE u.enabled = true
              ORDER BY u.id
              LIMIT 1"
        )->fetchAssociative();

        if ($adminRow === false) {
            $this->fail('No active admin user found in oro_user; cannot provision test OAuth2 client.');
        }

        $adminId = (int) $adminRow['id'];
        $orgId   = $adminRow['organization_id'] !== null ? (int) $adminRow['organization_id'] : null;

        // Hash the known plaintext secret with the same sodium algorithm Oro uses.
        $secretHash = sodium_crypto_pwhash_str(
            self::TEST_OAUTH_CLIENT_SECRET,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        // Upsert so repeated test runs are idempotent.
        // grants stored as Doctrine simple_array (comma-separated plain text).
        $conn->executeStatement(
            "INSERT INTO oro_oauth2_client
                (name, identifier, secret, salt, grants, scopes, redirect_uris,
                 active, frontend, organization_id,
                 owner_entity_class, owner_entity_id,
                 confidential, plain_text_pkce_allowed, skip_authorize_client_allowed)
             VALUES (?, ?, ?, '', 'client_credentials', NULL, NULL,
                     true, false, ?,
                     'Oro\\Bundle\\UserBundle\\Entity\\User', ?,
                     true, false, false)
             ON CONFLICT (identifier) DO UPDATE
               SET secret               = EXCLUDED.secret,
                   active               = true,
                   owner_entity_class   = EXCLUDED.owner_entity_class,
                   owner_entity_id      = EXCLUDED.owner_entity_id,
                   organization_id      = EXCLUDED.organization_id",
            [
                'Egerdau API Test Client',
                self::TEST_OAUTH_CLIENT_IDENTIFIER,
                $secretHash,
                $orgId,
                $adminId,
            ]
        );
    }

    /**
     * POST /oauth2-token with client_credentials grant and return the access_token string.
     */
    private function obtainClientCredentialsToken(string $clientId, string $clientSecret): string
    {
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $response = $this->doRequest('POST', '/oauth2-token', $body, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->fail(sprintf(
                'OAuth2 client_credentials grant returned HTTP %d. Body: %s',
                $response->getStatusCode(),
                $response->getContent()
            ));
        }

        return $this->extractAccessToken($response->getContent());
    }

    /**
     * Parse an access_token string from a JSON token-endpoint response body.
     */
    private function extractAccessToken(string $responseBody): string
    {
        $data  = json_decode($responseBody, true);
        $token = $data['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            $this->fail('OAuth2 token endpoint response did not contain a valid access_token. Body: ' . $responseBody);
        }

        return $token;
    }

    /**
     * Log in as a frontend customer user.
     *
     * Follows Oro's real login flow:
     *   1. GET /customer/user/login  — initialises the session cookie and reads the CSRF token.
     *   2. POST /customer/user/login-check  — submits credentials as form-encoded data.
     *
     * After this call all subsequent requests in the same test carry the authenticated
     * session cookie via the shared $cookieFile cookie jar.
     *
     * @throws \RuntimeException when the CSRF token cannot be extracted from the login page.
     */
    protected function login(string $email, string $password): void
    {
        // Step 1: fetch the login page — this sets the session cookie and embeds the CSRF token.
        $loginPage = $this->get('/customer/user/login');

        if (!preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage->getContent(), $matches)) {
            throw new \RuntimeException(
                'Could not extract _csrf_token from login page. '
                . 'HTTP status: ' . $loginPage->getStatusCode()
            );
        }
        $csrfToken = $matches[1];

        // Step 2: submit credentials as application/x-www-form-urlencoded (Oro's login form expectation).
        $loginData = http_build_query([
            '_username'   => $email,
            '_password'   => $password,
            '_csrf_token' => $csrfToken,
        ]);

        $this->doRequest('POST', '/customer/user/login-check', $loginData, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
    }

    /**
     * Compatibility shim for tests that call $this->request() with Symfony server-var style arrays.
     */
    protected function request(string $method, string $uri, array $server = [], array $content = []): Response
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', ucwords(strtolower(substr($key, 5)), '_'));
                $headers[$name] = $value;
            }
        }
        $body = !empty($content) ? json_encode($content) : null;
        return $this->doRequest($method, $uri, $body, $headers);
    }

    protected function doRequest(
        string $method,
        string $uri,
        ?string $body,
        array $headers,
        bool $followRedirects = false
    ): Response {
        $url = $this->baseUrl . $uri;

        $defaultHeaders = [
            'Accept'     => 'text/html,application/json,*/*;q=0.8',
            'User-Agent' => 'PHPUnit-Oro-IntegrationTest',
        ];
        $headers = array_merge($defaultHeaders, $headers);

        $responseHeaders = [];

        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 90);
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($curlHandle, CURLOPT_MAXREDIRS, 5);
        $sslVerify = $this->isSslVerifyEnabled();
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        // Persistent cookie jar — written after each response, read before each request.
        curl_setopt($curlHandle, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($curlHandle, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array_map(
            static fn ($k, $v) => "$k: $v",
            array_keys($headers),
            array_values($headers)
        ));
        curl_setopt($curlHandle, CURLOPT_HEADERFUNCTION, static function ($curl, string $header) use (&$responseHeaders): int {
            $len = strlen($header);
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }
            return $len;
        });

        if ($body !== null) {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curlHandle);
        $statusCode   = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($curlHandle);
        $curlErrno    = (int) curl_errno($curlHandle);
        curl_close($curlHandle);

        if ($curlErrno !== 0 || $statusCode === 0) {
            $this->markTestSkipped(
                "Server unreachable at {$url}: {$curlError}" .
                ' — start it with: symfony server:start -d --port=8000 --allow-all-ip --listen-ip=0.0.0.0'
            );
        }

        // Strip set-cookie headers — Symfony's Response constructor tries to parse them
        // and Oro's cookie SameSite values can cause InvalidArgumentException.
        unset($responseHeaders['set-cookie']);

        // Flatten multi-value headers; last value wins for Content-Type etc.
        $flatHeaders = array_map(static fn (array $v) => implode(', ', $v), $responseHeaders);

        return new Response((string) $responseBody, $statusCode, $flatHeaders);
    }
}
