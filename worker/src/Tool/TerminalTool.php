<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tool;

/**
 * The `terminal` internal tool — sandbox shell access.
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * !!!                                                                     !!!
 * !!!  TODO -- V1 BLOCKER -- DO NOT SHIP AS-IS                              !!!
 * !!!                                                                     !!!
 * !!!  This tool is currently a SAFETY STUB: it does NOT execute anything. !!!
 * !!!  It only echoes the would-be command back so the agent loop, routing  !!!
 * !!!  and audit logging can be proven end-to-end WITHOUT giving an LLM     !!!
 * !!!  arbitrary shell access to the sandbox.                              !!!
 * !!!                                                                     !!!
 * !!!  A real command runner must be implemented BEFORE v1 is called done:  !!!
 * !!!    - wrap in proc_open() with a strict timeout (e.g. 60s)             !!!
 * !!!    - run as a non-root, least-privilege user in the hardened image    !!!
 * !!!    - cap output size, stream to the LLM, never dump secrets           !!!
 * !!!    - upstream (TaskWeaver controller) must be able to deny it         !!!
 * !!!                                                                     !!!
 * !!!  Until then: echo-only. This is deliberate; a mistake here can run    !!!
 * !!!  arbitrary commands as the sandbox user.                             !!!
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 */
final class TerminalTool implements InternalTool
{
    public function name(): string
    {
        return 'terminal';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Run a shell command inside the worker sandbox. (Currently a safety stub — echoes the command instead of executing.)',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The shell command to run',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function run(array $arguments): array
    {
        $command = is_string($arguments['command'] ?? null) ? trim($arguments['command']) : '';

        if ($command === '') {
            return ['ok' => false, 'error' => 'terminal: missing required "command" argument'];
        }

        // TODO (v1 blocker): replace this echo stub with a real, sandboxed
        // command runner (see the class docblock). Until then we NEVER execute.
        return [
            'ok' => true,
            'output' => sprintf(
                "[terminal stub — not executed] would have run: %s\n" .
                '(TODO before v1: wire a real, sandboxed command runner.)',
                $command,
            ),
        ];
    }
}
