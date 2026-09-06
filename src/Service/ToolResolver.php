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
            if ($tool->matchesTags($stepTags)) {
                $schemas[] = [
                    'name' => $tool->getName(),
                    'scope' => 'external',
                    'schema' => $tool->getSchema(),
                    'server' => $tool->getServer()->getName(),
                ];
            }
        }

        return $schemas;
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

        if (!$tool->matchesTags($step->getTags())) {
            throw new ToolNotAllowedException(sprintf('Tool "%s" is not allowed for step tags [%s]', $toolName, implode(', ', $step->getTags())));
        }

        if (!$tool->getServer()->isEnabled()) {
            throw new ToolNotAllowedException(sprintf('Tool "%s" host server "%s" is disabled', $toolName, $tool->getServer()->getName()));
        }

        return $tool;
    }
}
