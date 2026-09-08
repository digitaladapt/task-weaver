<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiKey;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

use function is_array;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Full admin auth flow (penny-track pattern):
 *
 *   fresh install → /setup generates key → /api/auth/verify accepts it
 *   and establishes a session → admin pages reachable → bad key rejected
 *   → setup refuses a second key → CSRF enforced on admin POSTs.
 */
final class AdminAuthFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $em = $this->entityManager();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->resetLoginThrottling();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * The rate-limiter cache persists across tests in the same run (it lives
     * under var/cache/test). Clear that pool so each test starts from a
     * clean bucket — otherwise a login attempt in test A would carry over
     * and break test B.
     */
    private function resetLoginThrottling(): void
    {
        $pool = $this->client->getContainer()->get('cache.rate_limiter');
        $pool->clear();
    }

    private function seedKey(KernelBrowser $client): string
    {
        $client->request('POST', '/api/auth/setup');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        assert(is_array($data) && isset($data['api_key']));

        return (string) $data['api_key'];
    }

    public function testFreshInstallRedirectsToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testSetupPageShowsOnFreshInstall(): void
    {
        $this->client->request('GET', '/setup');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to TaskWeaver');
    }

    public function testSetupGeneratesKeyOnceThenRefuses(): void
    {
        $key = $this->seedKey($this->client);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);

        // The plaintext must NOT be stored — only its hash.
        $em = $this->entityManager();
        $stored = $em->createQuery('SELECT a FROM App\Entity\ApiKey a')->getSingleResult();
        assert($stored instanceof ApiKey);
        self::assertNotSame($key, $stored->getKeyHash());
        self::assertTrue(password_verify($key, $stored->getKeyHash()));

        // Second setup attempt is refused.
        $this->client->request('POST', '/api/auth/setup');
        self::assertResponseStatusCodeSame(409);

        // /setup now bounces to /login.
        $this->client->request('GET', '/setup');
        self::assertResponseRedirects('/login');
    }

    public function testVerifyAcceptsGoodKeyAndEstablishesSession(): void
    {
        $key = $this->seedKey($this->client);

        // No session yet: dashboard bounces to login.
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');

        // Bootstrap: verify with the key (header) → session established.
        $this->client->request('POST', '/api/auth/verify', server: ['HTTP_X_API_KEY' => $key]);
        self::assertResponseIsSuccessful();

        // Same client (session cookie) can now reach the dashboard.
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testVerifyRejectsBadKey(): void
    {
        $this->seedKey($this->client);

        $this->client->request(
            'POST',
            '/api/auth/verify',
            server: ['HTTP_X_API_KEY' => 'not-the-key', 'HTTP_ACCEPT' => 'application/json']
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testVerifyIsThrottledAfterFiveFailures(): void
    {
        // login_throttling: 5 attempts / 1 minute per IP (and per
        // IP+username). The sixth bad verify must be answered 429 Too Many
        // Requests, proving the limiter is wired into the main firewall.
        $this->seedKey($this->client);

        for ($i = 1; $i <= 5; ++$i) {
            $this->client->request(
                'POST',
                '/api/auth/verify',
                server: ['HTTP_X_API_KEY' => 'bad-key-'.$i, 'HTTP_ACCEPT' => 'application/json']
            );
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), "Attempt {$i} should be a plain 401");
        }

        $this->client->request(
            'POST',
            '/api/auth/verify',
            server: ['HTTP_X_API_KEY' => 'bad-key-one-too-many', 'HTTP_ACCEPT' => 'application/json']
        );
        self::assertResponseStatusCodeSame(429);
    }

    public function testWorkerApiUnaffectedByAdminAuth(): void
    {
        // Unauthenticated admin traffic bounces to /login; the worker API
        // keeps its own auth (401 JSON, never a login redirect).
        $this->client->request(
            'POST',
            '/api/worker/claim',
            server: ['HTTP_AUTHORIZATION' => 'Bearer bogus', 'HTTP_ACCEPT' => 'application/json']
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminPostRequiresCsrfToken(): void
    {
        $key = $this->seedKey($this->client);
        $this->client->request('POST', '/api/auth/verify', server: ['HTTP_X_API_KEY' => $key]);

        // Valid session but no CSRF token → 403.
        $this->client->request('POST', '/tasks/new', ['name' => 'x', 'description' => 'y']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testStatusEndpointReportsConfigurationState(): void
    {
        $this->client->request('GET', '/api/auth/status');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['configured' => false], $data);

        $this->seedKey($this->client);

        $this->client->request('GET', '/api/auth/status');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['configured' => true], $data);
    }
}
