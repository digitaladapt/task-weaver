<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\StepRepository;
use PHPUnit\Framework\TestCase;

/**
 * Claim matching (SPEC.md → Claiming & Matching).
 *
 * A worker must cover the step's SANDBOX capability tags; external-tool tags
 * (weather, echo, commands, …) are proxied by TaskWeaver for any worker and
 * thus never a claim requirement.
 */
final class StepRepositoryClaimMatcherTest extends TestCase
{
    /** @return string[] */
    private function externalTags(): array
    {
        return ['weather', 'echo', 'commands', 'demo-echo', 'demo-random', 'demo-time', 'status'];
    }

    public function testWorkerWithoutToolTagsCanClaimExternalToolStep(): void
    {
        // The production reference worker ships only `terminal`; the step
        // needs `weather` (an external tool) — the controller proxies it, so
        // the worker must NOT need the weather tag to claim.
        self::assertTrue(StepRepository::workerCoversStep(
            ['terminal'],
            ['weather'],
            $this->externalTags(),
        ));
    }

    public function testWorkerMustStillCoverSandboxCapabilityTags(): void
    {
        // Step needs terminal (sandbox) + weather (external). A worker with
        // no terminal cannot claim; one with terminal can.
        self::assertFalse(StepRepository::workerCoversStep(
            [],
            ['terminal', 'weather'],
            $this->externalTags(),
        ));
        self::assertTrue(StepRepository::workerCoversStep(
            ['terminal'],
            ['terminal', 'weather'],
            $this->externalTags(),
        ));
    }

    public function testWorkerWithNoTagsCanClaimPureExternalStep(): void
    {
        // A light worker with zero tags can still claim a step that only
        // requires external tools — TaskWeaver holds the tools.
        self::assertTrue(StepRepository::workerCoversStep(
            [],
            ['weather'],
            $this->externalTags(),
        ));
    }

    public function testUnknownTagIsTreatedAsCapabilityRequirement(): void
    {
        // A tag NOT backed by a live ToolDef is not an external tool tag, so
        // it stays a claim requirement (backward-compatible: unknown tags
        // behave like capabilities).
        self::assertFalse(StepRepository::workerCoversStep(
            ['terminal'],
            ['mystery-tag'],
            $this->externalTags(),
        ));
    }

    public function testRequiredCapabilityTagsStripsOnlyExternal(): void
    {
        self::assertSame(
            ['terminal', 'php'],
            StepRepository::requiredCapabilityTags(
                ['terminal', 'php', 'weather', 'echo'],
                $this->externalTags(),
            ),
        );
    }
}
