<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TaskWeaverWorker\ControllerClient;
use TaskWeaverWorker\LlmClient;

/**
 * Reference worker run loop.
 *
 * v1 scope: EXTERNAL tools only. The worker forwards every tool call to
 * TaskWeaver (the tool proxy) and records internal tools as a no-op — the
 * `terminal` internal tool is NOT supported yet (per initial-setup scope).
 *
 * Lifecycle per WORKER.md §5:
 *   boot → provision → loop { claim → fetch → [per step: running →
 *   event → LLM loop → tool calls → complete] } with abandon-on-denial.
 */
#[AsCommand(name: 'taskweaver:run', description: 'Run the reference worker loop')]
final class RunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('controller', null, InputOption::VALUE_REQUIRED, 'Controller base URL', getenv('TASKWEAVER_CONTROLLER_URL') ?: 'http://localhost:8000')
            ->addOption('enrollment-token', null, InputOption::VALUE_REQUIRED, 'Tier-0 enrollment token', getenv('TASKWEAVER_ENROLLMENT_TOKEN') ?: '')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Worker name', getenv('WORKER_NAME') ?: 'dev-worker')
            ->addOption('llm-url', null, InputOption::VALUE_REQUIRED, 'Local LLM base URL', getenv('TASKWEAVER_LLM_URL') ?: 'http://llm:11434/v1')
            ->addOption('llm-model', null, InputOption::VALUE_REQUIRED, 'Local LLM model', getenv('TASKWEAVER_LLM_MODEL') ?: 'llama3.1')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Claim and run one task, then exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $controller = $input->getOption('controller');
        $token = $input->getOption('enrollment-token');
        $name = $input->getOption('name');
        $llmUrl = $input->getOption('llm-url');
        $llmModel = $input->getOption('llm-model');
        $once = (bool) $input->getOption('once');

        // --- Boot / provision ---
        $client = new ControllerClient((string) $controller, (string) $token);
        $llm = new LlmClient((string) $llmUrl, (string) $llmModel);

        // Default descriptor uses the `dev-worker` variant so the controller
        // provisions this worker with the tags needed to claim the seeded
        // sample tasks (echo, weather). Swap the image for the variant you
        // actually run (e.g. taskweaver/worker:latest -> terminal only).
        $provisioned = $client->provision($name, ['image' => 'taskweaver/dev-worker:latest']);
        $output->writeln(sprintf('Provisioned worker %s (tags: %s)', $provisioned['worker_id'] ?? '?', implode(',', $provisioned['tags'] ?? [])));

        do {
            try {
                $claimed = $client->claim();
            } catch (HttpException $e) {
                $output->writeln(sprintf('<error>Claim denied (%d): %s — abandoning</error>', $e->status, $e->getMessage()));

                return Command::FAILURE;
            }

            $task = $claimed['task'] ?? null;
            $step = $claimed['step'] ?? null;

            if ($task === null || $step === null) {
                $output->writeln('No task available; sleeping...');
                if ($once) {
                    return Command::SUCCESS;
                }
                sleep(10);

                continue;
            }

            $taskId = (string) $task['id'];
            $stepId = (string) $step['id'];

            $output->writeln(sprintf('Claimed task %s step %s', $taskId, $stepId));

            try {
                $this->runStep($client, $llm, $taskId, $stepId, $output);
            } catch (HttpException $e) {
                if ($e->isDenial()) {
                    // Abandon-on-denial: the step's fate is already decided
                    // server-side. Clean up and move on.
                    $output->writeln(sprintf('<comment>Abandoning step %s (%d): %s</comment>', $stepId, $e->status, $e->getMessage()));
                } else {
                    $output->writeln(sprintf('<error>Step %s failed: %s</error>', $stepId, $e->getMessage()));
                }
            }

            if ($once) {
                return Command::SUCCESS;
            }
        } while (true);
    }

    private function runStep(ControllerClient $client, LlmClient $llm, string $taskId, string $stepId, OutputInterface $output): void
    {
        // Fetch task + schema, then mark running.
        $taskData = $client->fetchTask($taskId);
        $stepData = $this->findStep($taskData, $stepId);

        if ($stepData === null) {
            throw new HttpException('Step not in task data', 0);
        }

        $client->markRunning($taskId, $stepId);
        $output->writeln(sprintf('Step "%s" marked running', $stepData['name'] ?? $stepId));

        // Register an event → event-scoped key.
        $event = $client->registerEvent($taskId, $stepId);
        $eventId = (string) $event['event_id'];
        $eventKey = (string) $event['api_key'];
        $output->writeln(sprintf('Event %s registered', $eventId));

        // Build the step context.
        $tools = is_array($stepData['tools'] ?? null) ? $stepData['tools'] : [];
        $stepTags = is_array($stepData['tags'] ?? null) ? $stepData['tags'] : [];
        $stepName = (string) ($stepData['name'] ?? 'step');
        $stepDescription = (string) ($stepData['description'] ?? '');
        $isFinal = (bool) ($stepData['is_final'] ?? false);

        // The system prompt (static, image default) + grounding + assignment.
        $grounding = $this->grounding();
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($stepName, $stepTags, $isFinal)],
            ['role' => 'user', 'content' => $grounding . "\n\n" . $stepDescription],
        ];

        // LLM tool loop (v1: external tools only, all forwarded to TaskWeaver).
        $result = ['summary' => sprintf('Step "%s" ran with %d external tools available.', $stepName, count($tools))];

        while (true) {
            $response = $llm->chat($messages, $tools);
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            $toolCalls = $response['tool_calls'] ?? [];
            if ($toolCalls === []) {
                // No more tool calls — the step is done.
                if ($response['content'] !== '') {
                    $result = ['summary' => $response['content']];
                }
                break;
            }

            foreach ($toolCalls as $toolCall) {
                $fn = $toolCall['function'] ?? [];
                $toolName = (string) ($fn['name'] ?? '');
                $args = is_array($fn['arguments'] ?? null)
                    ? $fn['arguments']
                    : (json_decode((string) ($fn['arguments'] ?? '{}'), true) ?: []);

                if ($toolName === '') {
                    continue;
                }

                // External tool → forward to TaskWeaver with the event key.
                $output->writeln(sprintf('Calling external tool %s', $toolName));
                $callResult = $client->callTool($taskId, $eventId, $eventKey, $toolName, $args, null);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                    'content' => json_encode($callResult, JSON_THROW_ON_ERROR),
                ];
            }
        }

        // Submit the step result (revokes all event keys for this step).
        $client->complete($taskId, $stepId, $result);
        $output->writeln('<info>Step completed</info>');
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
        $now = new \DateTimeImmutable();

        return sprintf(
            "Current date/time: %s (UTC). Timezone: %s",
            $now->format('Y-m-d H:i:s'),
            $now->getTimezone()->getName(),
        );
    }

    private function systemPrompt(string $stepName, array $stepTags, bool $isFinal): string
    {
        $finalNote = $isFinal
            ? "\nThis is the FINAL step: you consume prior-step results and produce the task's final output."
            : '';

        return sprintf(
            "You are a TaskWeaver worker. You are running step \"%s\" (tags: %s).%s\n".
            "You may call the tools provided below. They are executed by the controller on your behalf.\n".
            "Raw tool data may be noisy; interpret it and answer only what the step asked for.",
            $stepName,
            implode(', ', $stepTags),
            $finalNote,
        );
    }
}
