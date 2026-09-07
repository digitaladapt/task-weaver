<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Step;
use App\Entity\ToolDef;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

/**
 * Resolves which external tools a step may use, and validates a specific
 * tool call against a step's tags before the proxy executes it.
 *
 * Tag rule (SPEC.md Design Principles #3/#7): a tool is allowed for a step
 * iff `tool.tags ∩ step.tags ≠ ∅`.
 */
final class ToolResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * External tool schemas allowed for a step (tag intersection), annotated
     * with scope = "external". Internal tools are resolved separately.
     *
     * @return array<int, array{name: string, scope: string, schema: array<string, mixed>, server: string}>
     */
    public function schemasForStep(Step $step): array
    {
        $stepTags = $step->getTags();
        $schemas = [];

        $toolDefs = $this->em->getRepository(ToolDef::class)->findAll();

        foreach ($toolDefs as $tool) {
            // Removed tools (no longer defined on their server) and tools on
            // disabled servers are not offered; their tags are preserved for
            // history but they must never reach steps.
            if ($tool->isRemoved() || !$tool->getServer()->isEnabled()) {
                continue;
            }
            if ($tool->matchesTags($stepTags)) {
                $schemas[] = [
                    'name' => $tool->getName(),
                    'scope' => 'external',
                    // Strip transport metadata (`x-mcp`, `x-mcp-server`) so the
                    // LLM only ever sees the tool's argument schema — the
                    // endpoint block is TaskWeaver-internal plumbing.
                    'schema' => $this->stripTransportMetadata($tool->getSchema()),
                    'server' => $tool->getServer()->getName(),
                ];
            }
        }

        return $schemas;
    }

    /**
     * Remove `x-mcp*` transport metadata keys from a tool schema so workers
     * and LLMs never receive endpoint plumbing. Only top-level keys are
     * stripped — the metadata never nests deeper.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function stripTransportMetadata(array $schema): array
    {
        foreach (array_keys($schema) as $key) {
            if (is_string($key) && str_starts_with($key, 'x-mcp')) {
                unset($schema[$key]);
            }
        }

        return $schema;
    }

    /**
     * Resolve a ToolDef by name and verify the step is allowed to call it.
     *
     * @throws ToolNotAllowedException when the tool is unknown or not authorized
     */
    public function resolveAllowed(Step $step, string $toolName): ToolDef
    {
        $tool = $this->em->getRepository(ToolDef::class)->findByName($toolName);

        if (null === $tool) {
            throw new ToolNotAllowedException(sprintf('Unknown tool "%s"', $toolName));
        }

        // Removed tools can never be called, regardless of tags.
        if ($tool->isRemoved()) {
            throw new ToolNotAllowedException(sprintf('Tool "%s" is no longer defined on its server', $toolName));
        }

        if (!$tool->matchesTags($step->getTags())) {
            throw new ToolNotAllowedException(sprintf('Tool "%s" is not allowed for step tags [%s]', $toolName, implode(', ', $step->getTags())));
        }

        if (!$tool->getServer()->isEnabled()) {
            throw new ToolNotAllowedException(sprintf('Tool "%s" host server "%s" is disabled', $toolName, $tool->getServer()->getName()));
        }

        return $tool;
    }
}
