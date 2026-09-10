<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Worker;
use App\Repository\WorkerRepository;
use App\Service\ProvisionService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Provisioning decides server-assigned capabilities: tags AND internal tools
 * are derived from the worker's descriptor — never self-declared by the worker.
 */
final class ProvisionServiceTest extends TestCase
{
    private function service(?Worker $existing = null, bool $llmProxied = false): ProvisionService
    {
        $repo = $this->createStub(WorkerRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $em = $this->createStub(EntityManagerInterface::class);

        return new ProvisionService(
            $em,
            $repo,
            'enrollment-token',
            50,
            1650,
            6750,
            1650,
            1,
            'http://llm:8080/v1',
            'Qwen3.5-4B',
            null,
            $llmProxied,
        );
    }

    public function testDevWorkerIsNotedWithTerminalInternalTool(): void
    {
        $result = $this->service()->provision('enrollment-token', 'dev-worker', ['image' => 'taskweaver/dev-worker:latest']);

        // dev-worker carries `terminal` tag -> terminal is a noted internal tool.
        self::assertContains('terminal', $result['tags']);
        self::assertContains('terminal', $result['internal_tools']);
    }

    public function testUnknownWorkerIsALightWorkerWithNoImplicitCapabilities(): void
    {
        $result = $this->service()->provision('enrollment-token', 'base-worker', ['image' => 'taskweaver/worker:latest']);

        // Unknown image + no declared capabilities -> no tags, no internal
        // tools. The old behavior granted every unrecognized image the
        // `terminal` tag; now a worker must tell us what it is.
        self::assertSame([], $result['tags']);
        self::assertSame([], $result['internal_tools']);
    }

    public function testLightWorkerEarnsOnlyDeclaredSanctionedCapabilities(): void
    {
        $result = $this->service()->provision('enrollment-token', 'custom-worker', [
            'image' => 'registry.example.com/corp/runner:1.2',
            'capabilities' => ['terminal', 'php'],
        ]);

        self::assertSame(['terminal', 'php'], $result['tags']);
        self::assertSame(['terminal'], $result['internal_tools']);
    }

    public function testLightWorkerCapabilityDeclarationIsFiltered(): void
    {
        // Only sandbox capabilities are self-declarable; tool tags and
        // junk are ignored even when declared.
        $result = $this->service()->provision('enrollment-token', 'liar', [
            'image' => 'registry.example.com/corp/runner:1.2',
            'capabilities' => ['echo', 'weather', 'terminal', 'god-mode'],
        ]);

        self::assertSame(['terminal'], $result['tags']);
        self::assertSame(['terminal'], $result['internal_tools']);
    }

    public function testLightWorkerStringCapabilitiesAreAccepted(): void
    {
        // Comma-separated string form (what the worker CLI sends).
        $result = $this->service()->provision('enrollment-token', 'string-cap', [
            'image' => 'registry.example.com/corp/runner:1.2',
            'capabilities' => 'terminal,php',
        ]);

        self::assertSame(['terminal', 'php'], $result['tags']);
    }

    public function testInternalToolsNeverSelfDeclaredByDescriptor(): void
    {
        // The descriptor may claim anything; the controller decides. Even if
        // a worker declared a bogus internal tool, it is not noted.
        $result = $this->service()->provision('enrollment-token', 'liar', ['image' => 'x', 'internal_tools' => ['hack']]);

        self::assertNotContains('hack', $result['internal_tools']);
        // Unknown image without declared capabilities → no terminal tool.
        self::assertSame([], $result['internal_tools']);
    }

    public function testInvalidTokenRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->provision('wrong', 'dev-worker', []);
    }

    public function testDevWorkerCanClaimDemoGauntletSteps(): void
    {
        $result = $this->service()->provision('enrollment-token', 'dev-worker', ['image' => 'taskweaver/dev-worker:latest']);

        // The dev-worker variant carries every demo tool tag so it can
        // claim all three gauntlet steps (worker tags ⊇ step tags).
        foreach (['demo-echo', 'demo-random', 'demo-time'] as $tag) {
            self::assertContains($tag, $result['tags']);
        }
    }

    public function testPublishedWorkerImageIsKnownAndCarriesTerminal(): void
    {
        // The compose default for the reference worker (docker-bake contract:
        // digitaladapt/task-weaver:latest-worker) must be a KNOWN image, not
        // a light worker with no capabilities. It ships the terminal sandbox
        // tool, so it earns the terminal tag + internal tool. External tool
        // tags (weather/echo/…) are deliberately NOT granted here — those are
        // proxy concerns, not worker capabilities (SPEC.md → Claiming).
        $result = $this->service()->provision('enrollment-token', 'worker-1', ['image' => 'digitaladapt/task-weaver:latest-worker']);

        self::assertContains('terminal', $result['tags']);
        self::assertContains('terminal', $result['internal_tools']);
        self::assertNotContains('weather', $result['tags']);
        self::assertNotContains('echo', $result['tags']);
    }

    public function testLlmAuthConfiguredDirectlyWhenNoKey(): void
    {
        $result = $this->service()->provision('enrollment-token', 'dev-worker', []);

        self::assertSame('direct', $result['config']['llm_auth']);
        self::assertSame('http://llm:8080/v1', $result['config']['llm_url']);
    }

    public function testLlmAuthProxiedWhenKeyConfigured(): void
    {
        $result = $this->service(null, true)->provision('enrollment-token', 'dev-worker', []);

        self::assertSame('proxy', $result['config']['llm_auth']);
        self::assertSame('/api/worker/llm', $result['config']['llm_url']);
    }
}
