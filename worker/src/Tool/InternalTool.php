<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tool;

/**
 * An internal (sandbox-local) tool that the worker can execute itself.
 *
 * Internal tools are the worker's own capabilities — memory, reasoning
 * helpers, and (eventually) terminal access. They run inside the worker's
 * sandbox and, unlike external tools, are never forwarded to the controller's
 * Tool Proxy. Executions are still reported to the controller via
 * POST /api/worker/tool/internal for auditability.
 */
interface InternalTool
{
    /**
     * The tool's canonical name, e.g. "terminal".
     */
    public function name(): string;

    /**
     * The schema advertised to the LLM (OpenAI function-calling format).
     *
     * @return array{description?: string, properties?: array<string, mixed>, required?: string[], type: string}
     */
    public function schema(): array;

    /**
     * Execute the tool locally.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{ok: bool, output?: string, error?: string}
     */
    public function run(array $arguments): array;
}
