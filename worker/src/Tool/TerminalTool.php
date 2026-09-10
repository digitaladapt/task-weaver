<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tool;

use function is_resource;
use function is_string;

use RuntimeException;

use function sprintf;
use function strlen;

/**
 * The `terminal` internal tool — sandbox shell access.
 *
 * Executes a single shell command inside the worker's sandbox via
 * proc_open(), with hard safety limits:
 *
 *  - strict timeout (default 60s) — the process is killed past it;
 *  - output cap (default 16 KiB) — the model never sees unbounded output,
 *    protecting the context budget;
 *  - executed via the same non-root user the worker itself runs as
 *    (enforced by the container, not by this class);
 *  - a blocklist of catastrophically destructive command patterns
 *    (defense in depth on top of the container's read-only rootfs, no
 *    capabilities, and ephemeral scratch — see WORKER.md §2).
 *
 * The blast-radius boundary is the CONTAINER (no internet, no secrets, no
 * host access); this class adds output/timeout discipline so a runaway
 * command can't hang the step loop or blow the context budget.
 */
final class TerminalTool implements InternalTool
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    private const DEFAULT_MAX_OUTPUT_BYTES = 16 * 1024;

    /** @var int timeout in seconds; 0 disables (not recommended) */
    private int $timeoutSeconds;

    /** @var int max captured stdout+stderr bytes */
    private int $maxOutputBytes;

    /**
     * Catastrophic patterns we refuse outright, even inside a sandbox.
     * Deliberately blunt: better a false positive than a wiped workspace.
     *
     * @var list<string>
     */
    private const BLOCKED_PATTERNS = [
        // Recursive force-deletes aimed at roots / home / mounts.
        'rm -rf /',
        'rm -rf /*',
        'rm -rf ~',
        'rm -rf ~/*',
        'rm -rf ./*',
        // Disk-level destruction.
        'mkfs',
        'dd if=/dev/zero',
        'dd if=/dev/urandom',
        // Fork bombs (incl. the compressed classic).
        ':(){:|:&};:',
        // Kernel/module-level tricks (no business in a sandbox).
        'modprobe',
        'insmod',
        // Container escape attempts.
        'nsenter',
        'unshare',
        'mount ',
        'umount',
    ];

    /**
     * @param array{timeout_seconds?: int, max_output_bytes?: int} $options
     */
    public function __construct(array $options = [])
    {
        $this->timeoutSeconds = max(1, (int) ($options['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS));
        $this->maxOutputBytes = max(1024, (int) ($options['max_output_bytes'] ?? self::DEFAULT_MAX_OUTPUT_BYTES));
    }

    public function name(): string
    {
        return 'terminal';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Run a shell command inside the worker sandbox. Non-interactive, single command, subject to a '.$this->timeoutSeconds.'s timeout and an output cap.',
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

        if ('' === $command) {
            return ['ok' => false, 'error' => 'terminal: missing required "command" argument'];
        }

        $blocked = $this->matchesBlockedPattern($command);
        if (null !== $blocked) {
            return [
                'ok' => false,
                'error' => sprintf('terminal: refused — command matches blocked pattern "%s". The sandbox protects the workspace; destructive disk-wide operations are not permitted.', $blocked),
            ];
        }

        try {
            return $this->execute($command);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'terminal: '.$e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, output?: string, error?: string, exit_code?: int, timed_out?: bool}
     */
    private function execute(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin — closed immediately; no interactive input
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open(
            ['/bin/bash', '-c', $command],
            $descriptors,
            $pipes,
            null,
            null,
            ['suppress_errors' => true],
        );

        if (!is_resource($process)) {
            throw new RuntimeException('failed to spawn process');
        }

        fclose($pipes[0]);

        // Read both pipes CONCURRENTLY with the timeout clock: a command
        // that never exits (sleep) or never closes its pipes would otherwise
        // block forever in a blocking read before the timeout could fire.
        $stdout = '';
        $stderr = '';
        $capped = false;
        $deadline = microtime(true) + $this->timeoutSeconds;

        $open = [$pipes[1], $pipes[2]];
        while ([] !== $open) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->killProcess($process, $pipes);

                return $this->timeoutResult($stdout, $stderr);
            }

            $read = $open;
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200_000);
            if (false === $ready) {
                break; // select error — treat like EOF
            }
            if (0 === $ready) {
                continue; // nothing ready yet; loop re-checks the deadline
            }

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if (false === $chunk || '' === $chunk) {
                    // EOF (or error) — drop this pipe from the watch set.
                    $open = array_values(array_filter($open, static fn ($p) => $p !== $pipe));
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
                if (strlen($stdout) + strlen($stderr) >= $this->maxOutputBytes) {
                    $capped = true;
                    break 2;
                }
            }
        }

        if ($capped) {
            // Output budget exhausted — kill and return what we have.
            $exitCode = -1;
            $this->killProcess($process, $pipes);
        } else {
            // Pipes drained — reap the exit status.
            $status = proc_get_status($process);
            $spinDeadline = microtime(true) + 5;
            while ($status['running'] && microtime(true) < $spinDeadline) {
                usleep(50_000);
                $status = proc_get_status($process);
            }
            $exitCode = $status['running'] ? -1 : ($status['exitcode'] ?? -1);
            $this->killProcess($process, $pipes);
        }

        $output = $stdout;
        if ('' !== $stderr) {
            $output .= ('' !== $output ? "\n" : '').'[stderr] '.$stderr;
        }
        if ($capped) {
            // Output budget exhausted — the command was killed mid-flight.
            // We can't know its real exit code, so report success (the
            // output is usable up to the cap) with the truncation marker.
            return [
                'ok' => true,
                'output' => substr($output, 0, $this->maxOutputBytes)."\n…[output truncated at ".$this->maxOutputBytes.' bytes]',
                'exit_code' => -1,
                'truncated' => true,
            ];
        }

        if (0 !== $exitCode) {
            return [
                'ok' => false,
                'error' => sprintf('terminal: command exited with code %d', $exitCode),
                'output' => $output,
                'exit_code' => $exitCode,
            ];
        }

        return [
            'ok' => true,
            'output' => $output,
            'exit_code' => 0,
        ];
    }

    /**
     * Kill the process and close any still-open pipes.
     *
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function killProcess($process, array $pipes): void
    {
        @proc_terminate($process, 9);
        foreach ($pipes as $index => $pipe) {
            if (0 !== $index && is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);
    }

    /**
     * @return array{ok: bool, error: string, output: string, exit_code: int, timed_out: bool}
     */
    private function timeoutResult(string $stdout, string $stderr): array
    {
        $output = $stdout;
        if ('' !== $stderr) {
            $output .= ('' !== $output ? "\n" : '').'[stderr] '.$stderr;
        }

        return [
            'ok' => false,
            'error' => sprintf('terminal: command timed out after %d seconds', $this->timeoutSeconds),
            'output' => substr($output, 0, $this->maxOutputBytes),
            'exit_code' => -1,
            'timed_out' => true,
        ];
    }

    /**
     * Check the command against the blocklist. Returns the matched pattern.
     */
    private function matchesBlockedPattern(string $command): ?string
    {
        // Two normalizations: collapse whitespace (catches 'rm   -rf   /'),
        // and fully strip it (catches the fork bomb in any spacing).
        $collapsed = preg_replace('/\s+/', ' ', strtolower($command)) ?? $command;
        $stripped = str_replace(' ', '', strtolower($command));

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains($collapsed, $pattern) || str_contains($stripped, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }
}
