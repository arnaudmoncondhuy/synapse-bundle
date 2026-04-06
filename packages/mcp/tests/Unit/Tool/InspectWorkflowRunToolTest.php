<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\InspectWorkflowRunTool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\InspectWorkflowRunTool
 */
class InspectWorkflowRunToolTest extends TestCase
{
    public function testInspectsRunSuccessfully(): void
    {
        $run = new SynapseWorkflowRun();
        $run->setWorkflowKey('test_wf');
        $run->setWorkflowVersion(1);
        $run->setStepsCount(2);
        $run->setStatus(WorkflowRunStatus::COMPLETED);
        $run->setCurrentStepIndex(2);
        $run->setTotalTokens(100);
        $run->setOutput(['result' => 'ok']);
        $run->setCompletedAt(new \DateTimeImmutable());

        $repo = $this->createStub(SynapseWorkflowRunRepository::class);
        $repo->method('findByWorkflowRunId')->willReturn($run);

        $tool = new InspectWorkflowRunTool($repo, $this->makeAdmin());
        $result = $tool($run->getWorkflowRunId());

        $this->assertSame('success', $result['status']);
        $this->assertSame('completed', $result['runStatus']);
        $this->assertSame('test_wf', $result['workflowKey']);
        $this->assertSame(2, $result['stepsCount']);
        $this->assertSame(100, $result['totalTokens']);
        $this->assertSame(['result' => 'ok'], $result['output']);
    }

    public function testReturnsErrorWhenNotFound(): void
    {
        $repo = $this->createStub(SynapseWorkflowRunRepository::class);
        $repo->method('findByWorkflowRunId')->willReturn(null);

        $tool = new InspectWorkflowRunTool($repo, $this->makeAdmin());
        $result = $tool('nonexistent-uuid');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    private function makeAdmin(): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(true);

        return $checker;
    }
}
