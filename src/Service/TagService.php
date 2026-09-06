<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\StepRepository;
use App\Repository\ToolDefRepository;
use App\Repository\WorkerRepository;

use function array_unique;
use function array_values;

use const SORT_FLAG_CASE;
use const SORT_STRING;

/**
 * Aggregates the universe of "known" tags for the admin UI's tag picker.
 *
 * Tags come from three sources:
 *   - ToolDefs (the tags describing what each external tool does),
 *   - Workers (the capability tags assigned to registered workers),
 *   - Steps already in the system (so user-typed tags survive editing).
 *
 * Soft-deleted tasks' steps are excluded (they're hidden, but their tags are
 * still valid suggestions — included here since they may be reused).
 *
 * Suggestions are sorted for stable rendering.
 */
final class TagService
{
    public function __construct(
        private readonly ToolDefRepository $tools,
        private readonly WorkerRepository $workers,
        private readonly StepRepository $steps,
    ) {
    }

    /**
     * @return string[] unique, sorted tag names
     */
    public function allKnown(): array
    {
        $tags = [];

        foreach ($this->tools->findAll() as $tool) {
            foreach ($tool->getTags() as $tag) {
                $tags[] = $tag;
            }
        }

        foreach ($this->workers->findAll() as $worker) {
            foreach ($worker->getTags() as $tag) {
                $tags[] = $tag;
            }
        }

        foreach ($this->steps->findAll() as $step) {
            foreach ($step->getTags() as $tag) {
                $tags[] = $tag;
            }
        }

        $tags = array_values(array_unique($tags));
        sort($tags, SORT_STRING | SORT_FLAG_CASE);

        return $tags;
    }
}
