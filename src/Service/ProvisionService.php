<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Worker;
use App\Repository\WorkerRepository;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;

use InvalidArgumentException;

use function is_array;
use function is_string;

/**
 * Worker provisioning (Tier-0 enrollment).
 *
 * A worker presents the one-time enrollment token at provision time and gets
 * back an ephemeral worker API key + controller-issued config. Capabilities
 * are server-assigned: the controller decides tags and internal tools based
 * on the worker's declared identity/descriptor — never self-declared by the
 * worker (SPEC.md Decisions Log #3).
 */
final class ProvisionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkerRepository $workers,
        private readonly string $enrollmentToken,
        private readonly int $maxRounds,
        private readonly int $stepTimeout,
        private readonly int $contextRequestSize,
        private readonly int $contextOutputBuffer,
        private readonly int $llmMaxConcurrency,
        private readonly string $llmUrl = 'http://llm:8080/v1',
        private readonly string $llmModel = 'Qwen3.5-4B',
        private readonly ?string $systemPromptOverride = null,
        private readonly string $llmApiKey = '',
        private readonly TimezoneService $timezone = new TimezoneService(),
    ) {
    }

    /**
     * Validate the enrollment token and provision (or refresh) a worker.
     *
     * @param array<string, mixed> $descriptor e.g. image name, declared capabilities (string or list)
     *
     * @return array{worker_id: string, api_key: string, tags: string[], internal_tools: string[], config: array<string, mixed>}
     */
    public function provision(string $token, string $name, array $descriptor = []): array
    {
        if (!hash_equals($this->enrollmentToken, $token)) {
            throw new InvalidArgumentException('Invalid enrollment token');
        }

        $worker = $this->workers->findOneBy(['name' => $name]);

        if (null === $worker) {
            // New worker: server-assigned tags. Base image always carries
            // `terminal`; variants add more (SPEC.md → Tech Stack → Workers).
            $tags = $this->assignTags($descriptor);
            $worker = new Worker($name, $tags);
            $worker->setInternalTools($this->assignInternalTools($tags));
            $this->em->persist($worker);
        } else {
            // Re-provisioning: rotate the key, refresh config/tags/tools.
            $worker->setTags($this->assignTags($descriptor));
            $worker->setInternalTools($this->assignInternalTools($worker->getTags()));
        }

        $proxied = '' !== $this->llmApiKey;

        $worker->setApiKey(KeyGenerator::generate());
        $worker->setConfig([
            'llm_url' => $proxied ? '/api/worker/llm' : $this->llmUrl,
            // 'proxy' → the worker POSTs chat payloads to the controller and
            // authenticates with its Tier-1 worker key (the controller holds
            // the provider key). 'direct' → the worker talks to the local LLM
            // itself, no auth needed. WORKER.md → The LLM channel.
            // Determined by presence of a provider key — never a boolean cast
            // of the key string (e.g. "sk-proj-..." is not FILTER_VALIDATE_BOOL
            // truthy, which used to silently disable proxying).
            'llm_auth' => $proxied ? 'proxy' : 'direct',
            'llm_model' => $this->llmModel,
            'system_prompt_override' => $this->systemPromptOverride,
            'max_rounds' => $this->maxRounds,
            'step_timeout' => $this->stepTimeout,
            'context' => [
                'request_size' => $this->contextRequestSize,
                'output_buffer_size' => $this->contextOutputBuffer,
            ],
            'llm_max_concurrency' => $this->llmMaxConcurrency,
            // Deployment timezone (SPEC.md → Configuration): the worker sets
            // its process default from this so grounding / any local time
            // math reports the same wall-clock as the controller.
            'timezone' => $this->timezone->resolve(),
        ]);
        $worker->markSeen();
        $this->em->flush();

        return [
            'worker_id' => $worker->getId()->toRfc4122(),
            'api_key' => (string) $worker->getApiKey(),
            'tags' => $worker->getTags(),
            'internal_tools' => $worker->getInternalTools(),
            'config' => $worker->getConfig(),
        ];
    }

    /**
     * Server-assigned tags from the descriptor.
     *
     * Two paths to capabilities (both still server-decided — the worker can
     * only ASK, the controller decides):
     *
     *  1. Known image variants get their tags from the server-side map.
     *  2. An unknown image is a "light worker": NO implicit capabilities.
     *     It only earns tags it explicitly declares in its descriptor
     *     (`capabilities: [terminal, php]`) — and only from the set the
     *     controller sanctions for self-declaration (sandbox capabilities
     *     the worker image actually ships, e.g. terminal/php/node). Tool
     *     tags (echo, weather, …) are never self-declarable; those describe
     *     what tasks the worker may claim, not what its sandbox can do.
     *
     * The old behavior — every unrecognized image fell back to
     * ['terminal'] — silently granted the terminal internal tool to any
     * worker we know nothing about. No more presumption.
     *
     * @param array<string, mixed> $descriptor
     *
     * @return string[]
     */
    private function assignTags(array $descriptor): array
    {
        // Server-side capability map (illustrative). Real deployments can
        // derive tags from the image name / registry metadata.
        $variantMap = [
            'php' => ['terminal', 'php'],
            'node' => ['terminal', 'node'],
            // Local dev worker able to claim the seeded sample tasks
            // (including the demo tool gauntlet).
            'dev-worker' => ['terminal', 'echo', 'weather', 'demo-echo', 'demo-random', 'demo-time'],
            // The published reference worker (compose default
            // digitaladapt/task-weaver:latest-worker) ships the terminal
            // sandbox tool, so it always earns the `terminal` capability.
            // External tool tags are NOT in this map — they are proxy
            // concerns, never worker claim requirements (SPEC.md → Claiming).
            'task-weaver' => ['terminal'],
        ];

        $image = (string) ($descriptor['image'] ?? '');
        foreach ($variantMap as $variant => $tags) {
            if (str_contains($image, $variant)) {
                return $tags;
            }
        }

        // Unknown image: light worker. Trust only explicitly declared
        // sandbox capabilities, intersected with the sanctioned set.
        $declared = $descriptor['capabilities'] ?? [];
        if (is_string($declared)) {
            $declared = array_filter(array_map('trim', explode(',', $declared)));
        }
        if (!is_array($declared)) {
            $declared = [];
        }

        // Sandbox capabilities a light worker may declare for itself.
        // Deliberately narrow: anything not here must come from a known
        // image variant (server-side map).
        $selfDeclarable = ['terminal', 'php', 'node'];

        return array_values(array_intersect($selfDeclarable, $declared));
    }

    /**
     * Server-assigned internal tools, derived from the assigned tags.
     * Internal tools are whatever the worker's sandbox image ships and the
     * controller notes here at provision time (SPEC.md Internal Tools).
     *
     * A worker carrying the `terminal` tag can run the `terminal` internal
     * tool locally; the controller notes it so the worker only ever exposes
     * sanctioned capabilities (never self-declared).
     *
     * @param string[] $tags
     *
     * @return string[]
     */
    private function assignInternalTools(array $tags): array
    {
        $tools = [];
        if (in_array('terminal', $tags, true)) {
            $tools[] = 'terminal';
        }

        return $tools;
    }
}
