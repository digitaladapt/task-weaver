<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Command;

use function count;
use function date_default_timezone_set;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function in_array;
use function is_array;
use function is_string;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use RuntimeException;

use function microtime;
use function sprintf;
use function strlen;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TaskWeaverWorker\ContextBudget;
use TaskWeaverWorker\ControllerClient;
use TaskWeaverWorker\HttpException;
use TaskWeaverWorker\LlmClient;
use TaskWeaverWorker\StepBudget;
use TaskWeaverWorker\Tool\InternalToolRegistry;
use TaskWeaverWorker\Tool\TerminalTool;

/**
 * Reference worker run loop.
 *
 * Lifecycle per WORKER.md §5:
 *   boot → provision → loop { claim → fetch → [per step: running →
 *   event → LLM loop → tool calls → complete] } with abandon-on-denial.
 *
 * The LLM loop is bounded and defensive:
 *  - unknown/invalid tool calls get an error result fed back to the model
 *    so it can correct itself (bounded rounds, then the step fails);
 *  - tool results are capped and history pruned to the context budget;
 *  - the system prompt honors the controller-issued override.
 */
#[AsCommand(name: 'taskweaver:run', description: 'Run the reference worker loop')]
final class RunCommand extends Command
{
    /** Bounded LLM round-trips per step before we give up on the model. */
    private const int MAX_LLM_ROUNDS = 50;

    /** Per-result token cap for tool results fed back to the model. */
    private const int TOOL_RESULT_TOKEN_CAP = 1500;

    protected function configure(): void
    {
        $this
            ->addOption('controller', null, InputOption::VALUE_REQUIRED, 'Controller base URL')
            ->addOption('enrollment-token', null, InputOption::VALUE_REQUIRED, 'Tier-0 enrollment token')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Worker name')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Image identity reported to the controller at provision time')
            ->addOption('capabilities', null, InputOption::VALUE_REQUIRED, 'Comma-separated sandbox capabilities this worker declares (e.g. terminal,php) — only honored for unknown images')
            ->addOption('llm-url', null, InputOption::VALUE_REQUIRED, 'Local LLM base URL')
            ->addOption('llm-model', null, InputOption::VALUE_REQUIRED, 'Local LLM model')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Claim and run one task, then exit')
            ->addOption('max-rounds', null, InputOption::VALUE_REQUIRED, 'Max LLM rounds per step')
            ->addOption('llm-stream', null, InputOption::VALUE_REQUIRED, 'Enable SSE streaming from the LLM (1/0)')
            ->addOption('step-idle-timeout', null, InputOption::VALUE_REQUIRED, 'Override the idle (silence) budget in seconds — must be lower than the controller\'s')
            ->addOption('step-timeout', null, InputOption::VALUE_REQUIRED, 'Override the end-to-end budget in seconds — can only shorten the controller\'s window')
            ->addOption('step-grace', null, InputOption::VALUE_REQUIRED, 'Override the grace period (seconds kept in reserve for transmitting)');
    }

    /* order of resolution:
     * 1. if worker invoked with "--<option> <value>" use that
     * 2. if worker environment has "<OPTION>=<value>" use that
     * 3. normal case: use the controller issued value
     * 4. fallback to reasonable default value
     * */
    protected function resolveVar(InputInterface $input, string $optionName, string $envName, string $fallback, string|int|null $issued = null): string
    {
        if ($input->hasParameterOption("--$optionName")) {
            return (string) $input->getOption($optionName);
        }
        $envVar = getenv($envName);
        if (false !== $envVar && '' !== $envVar) {
            return (string) $envVar;
        }
        if (null !== $issued && '' !== $issued) {
            return (string) $issued;
        }

        return $fallback;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $controller = $this->resolveVar($input, 'controller', 'TASKWEAVER_CONTROLLER_URL', 'http://controller:80');
        $token = $this->resolveVar($input, 'enrollment-token', 'TASKWEAVER_ENROLLMENT_TOKEN', '');
        $name = $this->resolveVar($input, 'name', 'WORKER_NAME', getenv('HOSTNAME') ?? 'worker-1');
        $image = $this->resolveVar($input, 'image', 'TASKWEAVER_WORKER_IMAGE', 'digitaladapt/task-weaver:latest-worker');
        $capabilityList = $this->resolveVar($input, 'capabilities', 'WORKER_CAPABILITIES', 'terminal');
        $descriptor = [
            'image' => $image,
            'capabilities' => array_filter(array_map('trim', explode(',', $capabilityList))),
        ];

        // --- Boot / provision ---
        $client = new ControllerClient($controller, $token);
        $provisioned = $client->provision($name, $descriptor);
        $output->writeln(sprintf('Provisioned worker %s (tags: %s)', $provisioned['worker_id'] ?? '?', implode(',', $provisioned['tags'] ?? [])));

        $config = is_array($provisioned['config'] ?? null) ? $provisioned['config'] : [];

        // Deployment-wide timezone (controller-issued, SPEC.md →
        // Configuration). Set PHP's default here so the worker's own
        // timestamps / grounding report the same wall-clock as the
        // controller — never the container's UTC default.
        $workerTimezone = $config['timezone'] ?? null;
        if (is_string($workerTimezone) && '' !== $workerTimezone) {
            // Validate the IANA name; only then set it as the process default.
            try {
                new DateTimeZone($workerTimezone);
                date_default_timezone_set($workerTimezone);
            } catch (Exception) {
                // Invalid controller-issued zone: stay on the process default.
            }
        }

        // The controller is the source of truth for the LLM channel
        // (WORKER.md provision -> config.llm_url). Prefer the controller's
        // issued value unless the operator explicitly pointed this worker at
        // a different LLM via --llm-url (CLI) or TASKWEAVER_LLM_URL (env) —
        // a local-debug escape hatch.
        $llmUrl = $this->resolveVar($input, 'llm-url', 'TASKWEAVER_LLM_URL', 'http://llm:8080/v1', $config['llm_url'] ?? null);

        // Model resolution, tier 1 of 4 (docs/model-selection-plan.md §5.1):
        // an operator override via --llm-model (CLI) or TASKWEAVER_LLM_MODEL
        // (env) wins for EVERY step this worker runs. Without it, the model
        // is resolved per claimed step (claim-issued step.model, else the
        // provision-config default) inside the run loop.
        $modelOverride = null;
        if ($input->hasParameterOption('--llm-model')) {
            $modelOverride = (string) $input->getOption('llm-model');
        } else {
            $envModel = getenv('TASKWEAVER_LLM_MODEL');
            if (false !== $envModel && '' !== $envModel) {
                $modelOverride = (string) $envModel;
            }
        }

        // Provision default (tier 3) — the client's initial model; each claim
        // may switch it per step.
        $llmModel = $modelOverride ?? (is_string($config['llm_model'] ?? null) && '' !== $config['llm_model'] ? (string) $config['llm_model'] : 'Qwen3.5-4B');

        // use max_rounds from controller unless explicit worker override
        $maxRounds = (int) $this->resolveVar($input, 'max-rounds', 'TASKWEAVER_MAX_ROUNDS', (string) self::MAX_LLM_ROUNDS, $config['max_rounds'] ?? null);

        // Operator clock overrides (CLI > env), mirroring resolveVar. These
        // win over the controller-issued budget so an operator can make this
        // worker MORE conservative than the fleet — never less: the
        // controller's deadlines stay authoritative (plan §3.9).
        $budgetOverrides = [];
        foreach (['step-idle-timeout' => 'step_idle_timeout', 'step-timeout' => 'step_timeout', 'step-grace' => 'step_grace'] as $opt => $key) {
            $envName = 'TASKWEAVER_'.strtoupper(str_replace('-', '_', $opt));
            if ($input->hasParameterOption('--'.$opt)) {
                $budgetOverrides[$key] = (string) $input->getOption($opt);
                continue;
            }
            $envValue = getenv($envName);
            if (false !== $envValue && '' !== $envValue) {
                $budgetOverrides[$key] = (string) $envValue;
            }
        }

        $once = (bool) $input->getOption('once');

        // A relative issued URL (proxy mode: '/api/worker/llm') resolves
        // against the controller base URL.
        if (str_starts_with($llmUrl, '/')) {
            $llmUrl = rtrim($controller, '/').$llmUrl;
        }

        // LLM channel: 'direct' (local LLM, no key) or 'proxy' (controller's
        // /api/worker/llm route, worker key authenticates, controller holds
        // the provider key).
        $llmAuth = is_string($config['llm_auth'] ?? null) ? $config['llm_auth'] : 'direct';
        $bearerToken = ('proxy' === $llmAuth) ? $client->workerKey() : null;

        // The controller issues a FULL endpoint for the proxy channel
        // (/api/worker/llm — it accepts the whole chat payload, exactly like
        // an OpenAI-compatible /chat/completions). In proxy mode the worker
        // must NOT append /chat/completions again.
        $llm = new LlmClient($llmUrl, $llmModel, $bearerToken, ['full_endpoint' => 'proxy' === $llmAuth]);

        // Context budget from the controller-issued config.
        $budget = ContextBudget::fromConfig($config);
        $llm->setContextBudget($budget->maxTokens(), $budget->requestSize() + $budget->maxTokens());

        // Streaming: on by default; operators can disable per-worker via
        // --llm-stream 0 or TASKWEAVER_LLM_STREAM=0 (provider that chokes
        // on SSE).
        $llmStream = '1' === $this->resolveVar($input, 'llm-stream', 'TASKWEAVER_LLM_STREAM', '1');
        $output->writeln(sprintf('LLM endpoint: %s (model: %s, auth: %s, stream: %s)', $llmUrl, $llmModel, $llmAuth, $llmStream ? 'on' : 'off'));
        $output->writeln(sprintf('Context budget: %d request + %d output tokens', $budget->requestSize(), $budget->maxTokens()));

        // System prompt: static image default, overrideable by the
        // controller-issued config (WORKER.md §6 → Prompt assembly).
        $systemPromptOverride = is_string($config['system_prompt_override'] ?? null) ? $config['system_prompt_override'] : '';
        if ('' !== $systemPromptOverride) {
            $output->writeln('Using controller-issued system prompt override');
        }

        // Internal (sandbox-local) tools this worker ships. We only expose
        // the tools the controller SANCTIONED at provision (WORKER.md §5:
        // capabilities are server-assigned, never self-declared).
        $sanctioned = is_array($provisioned['internal_tools'] ?? null) ? $provisioned['internal_tools'] : [];
        $internalTools = new InternalToolRegistry();
        $allInternal = [new TerminalTool()];
        foreach ($allInternal as $tool) {
            if (in_array($tool->name(), $sanctioned, true)) {
                $internalTools->register($tool);
            }
        }
        $output->writeln(sprintf('Internal tools (sanctioned): %s', implode(', ', $internalTools->names()) ?: '(none)'));

        while (true) {
            try {
                $claimed = $client->claim();
            } catch (HttpException $e) {
                $output->writeln(sprintf('<error>Claim denied (%d): %s — abandoning</error>', $e->status, $e->getMessage()));

                return Command::FAILURE;
            }

            $task = $claimed['task'] ?? null;
            $step = $claimed['step'] ?? null;

            if (null === $task || null === $step) {
                $output->writeln('No task available; sleeping...');
                if ($once) {
                    return Command::SUCCESS;
                }
                sleep(10);

                continue;
            }

            $taskId = (string) $task['id'];
            $stepId = (string) $step['id'];
            // Claim-issued step model (tier 2): already resolved by the
            // controller to override-or-default. Null only against an old
            // controller that doesn't send the field.
            $stepModel = isset($step['model']) && is_string($step['model']) && '' !== $step['model'] ? (string) $step['model'] : null;

            $output->writeln(sprintf('Claimed task %s step %s', $taskId, $stepId));

            try {
                $this->runStep($client, $llm, $internalTools, $budget, $systemPromptOverride, $taskId, $stepId, $stepModel, $modelOverride, $llmModel, $maxRounds, $llmStream, $config, $budgetOverrides, $output);
            } catch (HttpException $e) {
                if ($e->isDenial()) {
                    // Abandon-on-denial: the step's fate is already decided
                    // server-side. Clean up and move on.
                    $output->writeln(sprintf('<comment>Abandoning step %s (%d): %s</comment>', $stepId, $e->status, $e->getMessage()));
                } else {
                    $output->writeln(sprintf('<error>Step %s failed: %s</error>', $stepId, $e->getMessage()));
                }
            } catch (RuntimeException|JsonException $e) {
                // LLM unrecoverable / max rounds exceeded: report the
                // failure so the shape resolves now instead of waiting for
                // the lazy expiry deadline.
                $output->writeln(sprintf('<error>Step %s failed: %s</error>', $stepId, $e->getMessage()));
                try {
                    $client->reportFailure($taskId, $stepId, $e->getMessage());
                } catch (HttpException $reportFailure) {
                    $output->writeln(sprintf('<comment>Failure report rejected (%d): %s</comment>', $reportFailure->status, $reportFailure->getMessage()));
                }
            }

            if ($once) {
                return Command::SUCCESS;
            }
        }
    }

    private function runStep(
        ControllerClient $client,
        LlmClient $llm,
        InternalToolRegistry $internalTools,
        ContextBudget $budget,
        string $systemPromptOverride,
        string $taskId,
        string $stepId,
        ?string $stepModel,
        ?string $modelOverride,
        string $provisionModel,
        int $maxRounds,
        bool $llmStream,
        array $provisionConfig,
        array $budgetOverrides,
        OutputInterface $output,
    ): void {
        // Fetch task + schema, then mark running.
        $taskData = $client->fetchTask($taskId);
        $stepData = $this->findStep($taskData, $stepId);

        if (null === $stepData) {
            throw new HttpException('Step not in task data', 0);
        }

        // Per-step model resolution (§5.1): operator override > claim-issued
        // step model > provision default. Applied to the shared client; the
        // payload's model field is what actually runs.
        $effectiveModel = $modelOverride ?? $stepModel ?? $provisionModel;
        $llm->setModel($effectiveModel);

        // Arm the step's two clocks from the controller's budget block
        // (docs/step-liveness-plan.md §3.3).
        $stepBudget = StepBudget::fromStatusResponse(
            $client->markRunning($taskId, $stepId, $effectiveModel),
            $provisionConfig,
            null,
            $budgetOverrides,
        );

        // The LLM stream aborts on the same clocks the loop checks, so a
        // stalled generation cannot outlive the step.
        $llm->setIdleAbortSeconds($stepBudget->idleWindowSeconds());

        $output->writeln(sprintf('Step "%s" marked running (model: %s)', $stepData['name'] ?? $stepId, $effectiveModel));
        if ($stepBudget->isEnabled()) {
            $output->writeln(sprintf(
                'Clocks armed: idle %ds, e2e %ds, grace %ds',
                (int) $stepBudget->idleTimeout(),
                (int) $stepBudget->e2eRemainingSeconds(),
                (int) $stepBudget->grace(),
            ));
        }

        // Register an event → event-scoped key.
        $event = $client->registerEvent($taskId, $stepId);
        $eventId = (string) $event['event_id'];
        $eventKey = (string) $event['api_key'];
        $output->writeln(sprintf('Event %s registered', $eventId));

        // Build the step context: external tools come from the controller's
        // tag-matched schemas; internal tools are this worker's own registry,
        // scoped to the step's tags as well (WORKER.md §4: a step sees only
        // the tools its tags call for).
        $stepTags = is_array($stepData['tags'] ?? null) ? $stepData['tags'] : [];
        $tools = is_array($stepData['tools'] ?? null) ? $stepData['tools'] : [];
        $tools = array_merge($tools, $internalTools->schemasForStep($stepTags));
        $stepName = (string) ($stepData['name'] ?? 'step');
        $stepDescription = (string) ($stepData['description'] ?? '');
        $isFinal = (bool) ($stepData['is_final'] ?? false);

        // System prompt: always include step info; override replaces the base text.
        $system = $this->buildSystemPrompt($systemPromptOverride, $stepName, $stepTags, $isFinal);

        // Final step: consume the tool-call-results envelope of all prior
        // steps (SPEC.md → Final-Step Input). Non-final steps don't see it.
        $userContent = $this->grounding()."\n\n".$stepDescription;
        if ($isFinal) {
            $envelope = $this->buildEnvelope($taskData);
            if (null !== $envelope) {
                $userContent .= "\n\n## Prior step results\n\nThe following tool calls were executed for the earlier steps of this task. Consume their results as if you had issued them yourself:\n\n".$envelope;
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];

        // LLM tool loop: internal tools run locally, external tools are
        // forwarded to TaskWeaver. Both are presented to the LLM together.
        $result = ['summary' => sprintf('Step "%s" ran with %d tools available.', $stepName, count($tools))];

        $round = 0;
        $partialReason = null;

        while (true) {
            if (++$round > $maxRounds) {
                // Bounded rounds: the model kept calling tools (or emitting
                // garbage) without finishing. Fail the step with a clear
                // reason rather than looping forever.
                throw new RuntimeException(sprintf('Step exceeded %d LLM rounds without completing', $maxRounds));
            }

            // Budget check between rounds (docs/step-liveness-plan.md §3.4):
            // healthy, but out of time. Stop BEFORE issuing another LLM call
            // and submit whatever we have, rather than being cut off by a 401
            // with nothing to show for it.
            if ($stepBudget->shouldStop()) {
                $partialReason = $stepBudget->reason();
                break;
            }

            // Re-arm the e2e tripwire on the LLM client each round: a
            // generation still running when the deadline lands is abandoned
            // mid-stream with its partial output preserved.
            $llm->setE2eAbortAt(microtime(true) + $stepBudget->e2eRemainingSeconds());

            // Progress beats: while chunks arrive, tell the controller we are
            // still working so its rolling idle clock keeps moving (§3.5a).
            // Throttled to at most one beat per idle/3, and strictly
            // best-effort — a failed beat must never fail the step.
            $lastBeatAt = 0.0;
            $beatInterval = max(5.0, $stepBudget->idleWindowSeconds() / 3);
            $onChunk = function (int $chars) use ($output, $round, $client, $taskId, $stepId, $stepBudget, &$lastBeatAt, $beatInterval): void {
                if ($output->isVerbose()) {
                    $output->writeln(sprintf('  streamed %d chars (round %d)', $chars, $round));
                }

                $now = microtime(true);
                if ($now - $lastBeatAt < $beatInterval) {
                    return;
                }
                $lastBeatAt = $now;

                // Any streamed output is activity on both sides of the wire.
                $stepBudget->touch($now);

                try {
                    $client->reportProgress($taskId, $stepId);
                } catch (HttpException $e) {
                    // Losing a beat only risks the controller's backstop
                    // firing; it is never fatal (and a 401/409 here means the
                    // step is already decided — the next call will surface it).
                    $output->writeln(sprintf('<comment>Progress beat failed (%d): %s</comment>', $e->status, $e->getMessage()));
                }
            };

            $response = $llm->chat(
                $budget->pruneHistory($messages, $this->toolSchemaTokens($tools)),
                $tools,
                [
                    'stream' => $llmStream,
                    'on_chunk' => $onChunk,
                ],
            );

            // Bytes arrived, so the idle clock has been refreshed on both
            // sides; the e2e clock is untouched.
            $stepBudget->touch();

            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];

            // The stream was cut short by a budget tripwire. Keep whatever
            // arrived and stop: the partial output is the whole point
            // (§3.4/§3.5).
            if (($response['truncated'] ?? false) === true) {
                $partialReason = (string) ($response['reason'] ?? 'budget exhausted');
                if ('' !== $response['content']) {
                    $result = ['summary' => $response['content']];
                }
                break;
            }

            $toolCalls = $response['tool_calls'];
            if ([] === $toolCalls) {
                // No more tool calls — the step is done.
                if ('' !== $response['content']) {
                    $result = ['summary' => $response['content']];
                }
                break;
            }

            foreach ($toolCalls as $toolCall) {
                // Check before EVERY tool call: a long tool chain must not
                // push us past the deadline without a chance to submit.
                if ($stepBudget->shouldStop()) {
                    $partialReason = $stepBudget->reason();
                    break 2;
                }

                $fn = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                $toolName = (string) ($fn['name'] ?? '');
                $callId = (string) ($toolCall['id'] ?? '');
                $args = is_array($fn['arguments'] ?? null)
                    ? $fn['arguments']
                    : (json_decode((string) ($fn['arguments'] ?? '{}'), true) ?: []);

                // --- Robust tool-call handling: invalid calls get an error
                // result fed back so the model can correct itself. Never
                // crash the loop on a malformed call.
                if ('' === $toolName) {
                    $messages[] = $this->toolResultMessage($callId, ['ok' => false, 'error' => 'Malformed tool call: missing tool name']);
                    continue;
                }

                if ($internalTools->has($toolName)) {
                    // Internal tool → run locally in the sandbox, then log
                    // the execution to the controller for auditability.
                    $output->writeln(sprintf('Running internal tool %s', $toolName));
                    $callResult = $internalTools->get($toolName)->run($args);

                    // Tool work is step activity on the controller too.
                    $stepBudget->touch();

                    try {
                        $client->logInternalToolCall(
                            $eventId,
                            $toolName,
                            ['args' => $args, 'result' => $callResult],
                        );
                    } catch (HttpException $e) {
                        // Auditing must not fail the step: log locally and
                        // continue with the result we already have.
                        $output->writeln(sprintf('<comment>Internal tool log failed (%d): %s</comment>', $e->status, $e->getMessage()));
                    }
                } elseif ($this->isKnownTool($tools, $toolName)) {
                    // External tool → forward to TaskWeaver with the event key.
                    $output->writeln(sprintf('Calling external tool %s', $toolName));
                    $stepBudget->touch();
                    try {
                        $callResult = $client->callTool($taskId, $eventId, $eventKey, $toolName, $args, null);
                    } catch (HttpException $e) {
                        if ($e->isDenial()) {
                            // Denial → abandon the step (rethrow).
                            throw $e;
                        }
                        // Transient upstream error → feed the error back to
                        // the model; it may retry or work around.
                        $callResult = ['ok' => false, 'error' => $e->getMessage()];
                    }
                } else {
                    // Unknown/hallucinated tool → error result, no crash.
                    $callResult = [
                        'ok' => false,
                        'error' => sprintf('Unknown tool "%s". Available tools: %s', $toolName, implode(', ', $this->toolNames($tools))),
                    ];
                }

                // Cap the result before feeding back to the model.
                $callResult = $budget->capResult($callResult, self::TOOL_RESULT_TOKEN_CAP);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    // JSON_INVALID_UTF8_SUBSTITUTE: tool output (esp. the
                    // terminal tool) can carry non-UTF-8 bytes; substitute
                    // U+FFFD instead of failing the whole step.
                    'content' => json_encode($callResult, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ];
            }
        }

        // Submit the step result (revokes all event keys for this step).
        // A budget-truncated step is COMPLETED, not failed: failure semantics
        // persist no result, which would discard the partial output we just
        // fought to keep (docs/step-liveness-plan.md §3.5).
        //
        // A 401/409 here means the controller already decided this step (the
        // deadline landed, or it completed elsewhere) — HttpException
        // propagates so the outer loop applies abandon-on-denial.
        $client->complete($taskId, $stepId, $result, null !== $partialReason ? [
            'partial' => true,
            'reason' => $partialReason,
        ] : []);

        if (null !== $partialReason) {
            $output->writeln(sprintf('<comment>Step completed PARTIAL: %s</comment>', $partialReason));
        } else {
            $output->writeln('<info>Step completed</info>');
        }
    }

    /**
     * Whether the tool name is in this step's toolbox (external schema list
     * or internal registry). Protects against the model hallucinating tools.
     *
     * @param array<int, array<string, mixed>> $tools
     */
    private function isKnownTool(array $tools, string $toolName): bool
    {
        return in_array($toolName, $this->toolNames($tools), true);
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     *
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            if (is_string($tool['name'] ?? null)) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }

    /**
     * Token cost of the tool schemas sent with every request.
     *
     * @param array<int, array<string, mixed>> $tools
     */
    private function toolSchemaTokens(array $tools): int
    {
        $tokens = 0;
        foreach ($tools as $tool) {
            $tokens += strlen((string) json_encode($tool)) / 4;
        }

        return (int) $tokens;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array{role: string, tool_call_id: string, content: string}
     *
     * @throws JsonException
     */
    private function toolResultMessage(string $callId, array $result): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $callId,
            'content' => json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    /**
     * Build the tool-call-results envelope for a final step (SPEC.md →
     * Final-Step Input): every prior (non-final) step is one completed or
     * failed tool call in a single batch. Failed steps carry an error, no
     * result; completed ones carry their persisted result.
     *
     * @param array<string, mixed> $taskData
     */
    private function buildEnvelope(array $taskData): ?string
    {
        $entries = [];
        $i = 0;
        foreach (($taskData['steps'] ?? []) as $step) {
            if (!is_array($step) || ($step['is_final'] ?? false)) {
                continue;
            }
            ++$i;
            $name = (string) ($step['name'] ?? ('step '.$i));
            $status = (string) ($step['status'] ?? '');
            $stepResult = $step['result'] ?? null;

            if ('completed' === $status && null !== $stepResult) {
                $entry = [
                    'tool_name' => 'step/'.$i,
                    'arguments' => ['step' => $i, 'name' => $name],
                    'result' => $stepResult,
                    'status' => 'completed',
                ];

                // The step hit a budget and submitted what it had. Flag it in
                // the envelope so the final step does not treat a truncated
                // input as whole (docs/step-liveness-plan.md §3.5).
                if (($step['partial'] ?? false) === true) {
                    $entry['partial'] = true;
                    $entry['note'] = sprintf('step "%s" was truncated before it finished; its result is incomplete', $name);
                }

                $entries[] = $entry;
            } else {
                $entries[] = [
                    'tool_name' => 'step/'.$i,
                    'arguments' => ['step' => $i, 'name' => $name],
                    'error' => sprintf('step "%s" did not complete (status: %s)', $name, '' !== $status ? $status : 'unknown'),
                    'status' => 'failed',
                ];
            }
        }

        if ([] === $entries) {
            return null;
        }

        return (string) (json_encode(['tool_calls' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /**
     * @param array<string, mixed> $taskData
     *
     * @return array<string, mixed>|null
     */
    private function findStep(array $taskData, string $stepId): ?array
    {
        foreach (($taskData['steps'] ?? []) as $step) {
            if (is_array($step) && ($step['id'] ?? null) === $stepId) {
                return $step;
            }
        }

        return null;
    }

    private function grounding(): string
    {
        $now = new DateTimeImmutable();

        return sprintf(
            'Current date/time: %s (%s)',
            $now->format('Y-m-d H:i:s'),
            $now->getTimezone()->getName(),
        );
    }

    /**
     * Always include step info; a controller-issued override replaces the
     * base worker persona text, not the step framing.
     */
    private function buildSystemPrompt(string $override, string $stepName, array $stepTags, bool $isFinal): string
    {
        $finalNote = $isFinal
            ? "\nThis is the FINAL step: you consume prior-step results and produce the task's final output."
            : '';

        $base = '' !== $override
            ? $override
            : "You are a TaskWeaver worker.\n".
              "You may call the tools provided below. They are executed by the controller on your behalf.\n".
              'Raw tool data may be noisy; interpret it and answer only what the step asked for.';

        return sprintf(
            "%s\nYou are running step \"%s\" (tags: %s).%s",
            $base,
            $stepName,
            implode(', ', $stepTags),
            $finalNote,
        );
    }
}
