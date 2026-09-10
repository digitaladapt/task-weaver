<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use function assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Provisioning must reflect the actual LLM channel configuration at the
 * container level (SPEC.md → LLM channel).
 *
 * Regression: config/services.yaml used `%env(bool:TASKWEAVER_LLM_API_KEY)%`
 * to decide proxy-vs-direct. Symfony's env(bool:) casts with
 * filter_var(FILTER_VALIDATE_BOOL) — a real key string like "sk-proj-…" is
 * NOT a valid boolean token and therefore always evaluated to FALSE. Workers
 * were provisioned with `llm_auth: direct` even when a provider key was set,
 * so the controller never proxied their LLM traffic.
 *
 * (The direct-channel counterpart is covered by ProvisionServiceTest; here we
 * boot the real container so the services.yaml wiring — the part the unit
 * tests can't see — is exercised.)
 */
final class WorkerProvisionLlmProxyTest extends WebTestCase
{
    private const ENROLLMENT_TOKEN = 'test-enrollment-token';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // The container resolves env() lazily from $_ENV/$_SERVER at runtime;
        // surface a realistic provider key so the wire-up is exercised.
        $_ENV['TASKWEAVER_LLM_API_KEY'] = 'sk-proj-real-provider-key-123456';
        $_SERVER['TASKWEAVER_LLM_API_KEY'] = 'sk-proj-real-provider-key-123456';

        $this->client = static::createClient();

        $em = $this->entityManager();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        unset($_ENV['TASKWEAVER_LLM_API_KEY'], $_SERVER['TASKWEAVER_LLM_API_KEY']);

        parent::tearDown();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function testProvisionIssuesProxyChannelWhenProviderKeyIsSet(): void
    {
        $this->client->request('POST', '/api/worker/provision', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::ENROLLMENT_TOKEN,
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'name' => 'worker-proxy',
            'descriptor' => ['image' => 'digitaladapt/task-weaver:latest-worker'],
        ]));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        assert(is_array($data));

        self::assertSame('proxy', $data['config']['llm_auth'] ?? null);
        self::assertSame('/api/worker/llm', $data['config']['llm_url'] ?? null);
    }
}
