<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Worker;
use App\Repository\WorkerRepository;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;

use InvalidArgumentException;

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
        private readonly int $stepTimeout,
        private readonly int $contextRequestSize,
        private readonly int $contextOutputBuffer,
        private readonly int $llmMaxConcurrency,
        private readonly string $llmUrl = 'http://llm:11434/v1',
        private readonly string $llmModel = 'llama3.1',
        private readonly ?string $systemPromptOverride = null,
    ) {
    }

    /**
     * Validate the enrollment token and provision (or refresh) a worker.
     *
     * @param array<string, string> $descriptor e.g. image name, requested tags hint
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

        $worker->setApiKey(KeyGenerator::generate());
        $worker->setConfig([
            'llm_url' => $this->llmUrl,
            'llm_model' => $this->llmModel,
            'system_prompt_override' => $this->systemPromptOverride,
            'step_timeout' => $this->stepTimeout,
            'context' => [
                'request_size' => $this->contextRequestSize,
                'output_buffer_size' => $this->contextOutputBuffer,
            ],
            'llm_max_concurrency' => $this->llmMaxConcurrency,
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
     * Server-assigned tags from the descriptor. The base image's `terminal`
     * tag is always present; capability tags come from a server-side map.
     *
     * @param array<string, string> $descriptor
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
            // Local dev worker able to claim the seeded sample tasks.
            'dev-worker' => ['terminal', 'echo', 'weather'],
        ];

        $image = $descriptor['image'] ?? '';
        foreach ($variantMap as $variant => $tags) {
            if (str_contains($image, $variant)) {
                return $tags;
            }
        }

        return ['terminal'];
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
