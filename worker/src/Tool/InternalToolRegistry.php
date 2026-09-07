<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tool;

use RuntimeException;

/**
 * Registry of the internal (sandbox-local) tools this worker ships.
 *
 * The worker advertises these to the LLM alongside the controller-provided
 * external tools, and dispatches calls to them locally instead of forwarding
 * to the controller's Tool Proxy. See WORKER.md §5d: an internal tool is
 * "run locally → /tool/internal log".
 */
final class InternalToolRegistry
{
    /**
     * @var array<string, InternalTool>
     */
    private array $tools = [];

    /**
     * @param iterable<InternalTool> $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(InternalTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): InternalTool
    {
        if (!isset($this->tools[$name])) {
            throw new RuntimeException(sprintf('Unknown internal tool "%s"', $name));
        }

        return $this->tools[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Advertise all internal tools to the LLM in function-calling format.
     *
     * @return array<int, array{name: string, description: ?string, schema: array<string, mixed>}>
     */
    public function schemas(): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            $schemas[] = [
                'name' => $tool->name(),
                'description' => $tool->schema()['description'] ?? null,
                'schema' => $tool->schema(),
            ];
        }

        return $schemas;
    }
}
