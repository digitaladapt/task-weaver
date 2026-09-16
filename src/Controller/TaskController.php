<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Step;
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\ModelCatalogService;
use App\Service\ScheduleCronService;
use App\Service\SchedulerService;
use App\Service\TagService;
use App\Service\TaskWorkflowService;
use App\Service\TimezoneService;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;

use Cron\CronExpression;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function explode;
use function in_array;
use function is_array;

use LogicException;

use function max;
use function sort;

use const SORT_NUMERIC;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

use function trim;
use function usort;

/**
 * Task browsing + management (SPEC.md → Admin UI).
 *
 * Create / edit / soft-delete tasks via the admin UI. Steps are edited with a
 * dynamic editor: the final step is fixed at the bottom, all non-final steps
 * sit above it. Soft-deleted tasks are hidden and frozen, but their steps and
 * events are preserved for history.
 */
#[Route('/tasks')]
final class TaskController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TagService $tags,
        private readonly ScheduleCronService $schedule,
        private readonly TimezoneService $timezone,
        private readonly SchedulerService $scheduler,
        private readonly ModelCatalogService $models,
    ) {
    }

    /**
     * Per-form-index unknown-model warnings (M6 soft validation): filled by
     * applyForm, rendered as a non-blocking note above the field.
     *
     * @var array<int, string>
     */
    private array $unknownStepModels = [];

    /**
     * @var string[]|null lazily-populated cached live model list
     */
    private ?array $knownModels = null;

    /**
     * @return string[]
     */
    private function knownModels(): array
    {
        if (null === $this->knownModels) {
            $this->knownModels = $this->models->list()['models'];
        }

        return $this->knownModels;
    }

    #[Route('', name: 'app_tasks', methods: ['GET'])]
    public function index(TaskRepository $tasks): Response
    {
        // Soft-deleted tasks are hidden from the admin UI (SPEC.md → Soft Delete).
        return $this->render('admin/tasks/index.html.twig', [
            'tasks' => $tasks->findAllActive(),
        ]);
    }

    #[Route('/new', name: 'app_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $task = new Task('', '');
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->applyForm($task, $request->request->all());
            $errors = $this->validate($task);

            if ([] === $errors) {
                $this->em->persist($task);
                $this->em->flush();
                $this->addFlash('success', 'Task created.');

                return $this->redirectToRoute('app_task_show', ['id' => $task->getId()->toRfc4122()]);
            }
        } else {
            // A brand-new task starts with a single (final) step.
            $step = new Step('', '');
            $step->setIsFinal(true);
            $task->addStep($step);
        }

        return $this->render('admin/tasks/form.html.twig', [
            'task' => $task,
            'all_tags' => $this->tags->allKnown(),
            'errors' => $errors,
            'is_new' => true,
            'schedule' => $this->schedule->describe($task->getSchedule()),
            'known_models' => $this->knownModels(),
            'default_model' => $this->models->list()['default'],
            'unknown_step_models' => $this->unknownStepModels,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_task_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Request $request, TaskRepository $tasks, string $id): Response
    {
        $task = $tasks->find(Uuid::fromString($id)->toRfc4122());
        if (!$task instanceof Task || $task->isDeleted()) {
            throw $this->createNotFoundException('Task not found');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $this->applyForm($task, $request->request->all());
            $errors = $this->validate($task);

            if ([] === $errors) {
                $task->touch();
                $this->em->flush();
                $this->addFlash('success', 'Task updated.');

                return $this->redirectToRoute('app_task_show', ['id' => $task->getId()->toRfc4122()]);
            }
        }

        return $this->render('admin/tasks/form.html.twig', [
            'task' => $task,
            'all_tags' => $this->tags->allKnown(),
            'errors' => $errors,
            'is_new' => false,
            'schedule' => $this->schedule->describe($task->getSchedule()),
            'known_models' => $this->knownModels(),
            'default_model' => $this->models->list()['default'],
            'unknown_step_models' => $this->unknownStepModels,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_task_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(TaskRepository $tasks, string $id): Response
    {
        $task = $tasks->find(Uuid::fromString($id)->toRfc4122());
        if (!$task instanceof Task || $task->isDeleted()) {
            throw $this->createNotFoundException('Task not found');
        }

        // Soft delete: hide from view and disable from running, but keep the
        // task, its steps, and its events for history preservation.
        $task->softDelete();
        $this->em->flush();
        $this->addFlash('success', sprintf('Task "%s" deleted.', $task->getName()));

        return $this->redirectToRoute('app_tasks');
    }

    #[Route('/{id}', name: 'app_task_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(TaskRepository $tasks, string $id): Response
    {
        $task = $tasks->find(Uuid::fromString($id)->toRfc4122());
        if (!$task instanceof Task || $task->isDeleted()) {
            throw $this->createNotFoundException('Task not found');
        }

        return $this->render('admin/tasks/show.html.twig', [
            'task' => $task,
        ]);
    }

    #[Route('/{id}/run', name: 'app_task_run', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function run(TaskRepository $tasks, TaskWorkflowService $workflow, string $id): Response
    {
        $task = $tasks->find(Uuid::fromString($id)->toRfc4122());
        if (!$task instanceof Task || $task->isDeleted()) {
            throw $this->createNotFoundException('Task not found');
        }

        try {
            $workflow->resetForRun($task);
            $this->em->flush();
        } catch (LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_task_show', ['id' => $id]);
        }

        $this->addFlash('success', sprintf('Task "%s" queued for the next available worker.', $task->getName()));

        return $this->redirectToRoute('app_task_show', ['id' => $id]);
    }

    /**
     * Apply the submitted form to the task/step graph.
     *
     * The UI guarantees the final step is always the last submitted block, so
     * finality is derived from position (last row = final). Steps that were
     * removed from the editor are dropped only if they never started — a step
     * that already ran is kept for history.
     *
     * @param array<string, mixed> $data
     */
    private function applyForm(Task $task, array $data): void
    {
        $task->setName(trim((string) ($data['name'] ?? '')));
        $task->setDescription(trim((string) ($data['description'] ?? '')));
        // Priorities are signed: -999 (lowest) .. 999 (highest). The simple
        // form has no validation, so clamp any out-of-range submission.
        $priority = max(-999, min(999, (int) ($data['priority'] ?? 0)));
        $task->setPriority($priority);

        // Timezone is deployment-wide (TASKWEAVER_TIMEZONE env, else system
        // default); no per-task field. Stamp it so the scheduler stays
        // timezone-aware. The task.timezone column stays for future
        // multi-user support.
        $task->setTimezone($this->timezone->resolve());

        // Schedule is now a structured selection; build the cron from it.
        $task->setSchedule($this->schedule->build($data));

        // A scheduled task always exposes a concrete "Next Run" — compute it
        // now (from the cron) so enabling a schedule shows immediately instead
        // of staying blank until the next scheduler tick. One-shot tasks keep
        // a nil next run (they run on demand via /tasks/{id}/run).
        if (null !== $task->getSchedule()) {
            $task->setNextRunAt($this->scheduler->nextRunAt($task, new DateTimeImmutable()));
        }

        $raw = $data['steps'] ?? [];
        $names = is_array($raw['name'] ?? null) ? $raw['name'] : [];
        $indexes = array_keys($names);
        sort($indexes, SORT_NUMERIC);
        $count = count($indexes);

        // Index existing steps by id so we can update in place.
        $existing = [];
        foreach ($task->getSteps() as $step) {
            $existing[$step->getId()->toRfc4122()] = $step;
        }

        $kept = [];
        $newSteps = [];
        foreach ($indexes as $order => $idx) {
            $stepId = trim((string) ($raw['id'][$idx] ?? ''));

            if ('' !== $stepId && isset($existing[$stepId])) {
                $step = $existing[$stepId];
                $kept[$stepId] = true;
            } else {
                $step = new Step('', '');
                $newSteps[] = $step;
            }

            $step->setName(trim((string) ($raw['name'][$idx] ?? '')));
            $step->setDescription(trim((string) ($raw['description'][$idx] ?? '')));

            $tagString = (string) ($raw['tags'][$idx] ?? '');
            $tagList = array_values(array_filter(array_map('trim', explode(',', $tagString))));
            $step->setTags($tagList);

            // Per-step model override (nullable; empty → default).
            $step->setModel(trim((string) ($raw['model'][$idx] ?? '')));

            // Soft warning (M6): the value isn't in the cached live list —
            // keep it (it may be valid tomorrow, or on another worker) but
            // surface it to the operator above the field.
            $modelValue = $step->getModel();
            if (null !== $modelValue && !in_array($modelValue, $this->knownModels(), true)) {
                $this->unknownStepModels[$idx] = $modelValue;
            }

            $step->setSortOrder($order);
            // The final step is always the last block in the editor.
            $step->setIsFinal($order === $count - 1);
        }

        // Remove dropped steps. A step that has already started is kept for
        // history (its events are the record), even if it left the editor.
        $toRemove = [];
        foreach ($task->getSteps() as $step) {
            $id = $step->getId()->toRfc4122();
            if (isset($kept[$id])) {
                continue;
            }
            if (null !== $step->getStartedAt()) {
                continue;
            }
            $toRemove[] = $step;
        }
        foreach ($toRemove as $step) {
            $task->removeStep($step);
        }

        foreach ($newSteps as $step) {
            $task->addStep($step);
        }

        // Normalize: exactly one final step — the last by sort order. This
        // keeps the task shape valid even if a malformed submission dropped
        // the previous final step (e.g. a duplicate-final edge case).
        $ordered = $task->getSteps()->toArray();
        usort($ordered, static fn (Step $a, Step $b) => $a->getSortOrder() <=> $b->getSortOrder());
        $last = count($ordered) - 1;
        foreach ($ordered as $i => $step) {
            $step->setIsFinal($i === $last);
        }
    }

    /**
     * Validate the task before persisting.
     *
     * @return array<string, string> field => message
     */
    private function validate(Task $task): array
    {
        $errors = [];

        if ('' === trim($task->getName())) {
            $errors['name'] = 'Task name is required.';
        }

        $schedule = $task->getSchedule();
        if (null !== $schedule && !CronExpression::isValidExpression($schedule)) {
            $errors['schedule'] = sprintf('"%s" is not a valid cron expression.', $schedule);
        }

        if (0 === $task->getSteps()->count()) {
            $errors['steps'] = 'At least one step is required.';
        } else {
            foreach ($task->getSteps() as $step) {
                if ('' === trim($step->getName())) {
                    $errors['step_name'] = 'Every step needs a name.';
                    break;
                }
            }
        }

        return $errors;
    }
}
