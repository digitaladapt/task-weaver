<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Worker;
use App\Repository\WorkerRepository;
use App\Service\ProvisionService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Provisioning decides server-assigned capabilities: tags AND internal tools
 * are derived from the worker's descriptor — never self-declared by the worker.
 */
final class ProvisionServiceTest extends TestCase
{
    private function service(?Worker $existing = null): ProvisionService
    {
        $repo = $this->createStub(WorkerRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $em = $this->createStub(EntityManagerInterface::class);

        return new ProvisionService(
            $em,
            $repo,
            'enrollment-token',
            600,
            6000,
            1500,
            1,
        );
    }

    public function testDevWorkerIsNotedWithTerminalInternalTool(): void
    {
        $result = $this->service()->provision('enrollment-token', 'dev-worker', ['image' => 'taskweaver/dev-worker:latest']);

        // dev-worker carries `terminal` tag -> terminal is a noted internal tool.
        self::assertContains('terminal', $result['tags']);
        self::assertContains('terminal', $result['internal_tools']);
    }

    public function testBaseWorkerGetsTerminalOnly(): void
    {
        $result = $this->service()->provision('enrollment-token', 'base-worker', ['image' => 'taskweaver/worker:latest']);

        // No variant matched -> base tags ['terminal'] + its internal tool.
        self::assertSame(['terminal'], $result['tags']);
        self::assertSame(['terminal'], $result['internal_tools']);
    }

    public function testInternalToolsNeverSelfDeclaredByDescriptor(): void
    {
        // The descriptor may claim anything; the controller decides. Even if
        // a worker declared a bogus internal tool, it is not noted.
        $result = $this->service()->provision('enrollment-token', 'liar', ['image' => 'x', 'internal_tools' => ['hack']]);

        self::assertNotContains('hack', $result['internal_tools']);
        self::assertContains('terminal', $result['internal_tools']);
    }

    public function testInvalidTokenRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->provision('wrong', 'dev-worker', []);
    }
}
