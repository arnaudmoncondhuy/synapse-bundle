<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\CleanupSandboxTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\CleanupSandboxTool
 */
class CleanupSandboxToolTest extends TestCase
{
    public function testCleansUpAllSandboxEntities(): void
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('sb_wf');

        $agent = new SynapseAgent();
        $agent->setKey('sb_agent');

        $preset = new SynapseModelPreset();
        $preset->setKey('sb_preset');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3))->method('remove');
        $em->expects($this->once())->method('flush');

        $workflowRepo = $this->createStub(SynapseWorkflowRepository::class);
        $workflowRepo->method('findSandbox')->willReturn([$workflow]);

        $runRepo = $this->createMock(SynapseWorkflowRunRepository::class);
        $runRepo->expects($this->once())
            ->method('deleteByWorkflowKeys')
            ->with(['sb_wf'])
            ->willReturn(2);

        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findSandbox')->willReturn([$agent]);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findSandbox')->willReturn([$preset]);

        $tool = new CleanupSandboxTool($em, $agentRepo, $workflowRepo, $runRepo, $presetRepo, $this->makeAdmin());
        $result = $tool();

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['workflowRunsDeleted']);
        $this->assertSame(1, $result['workflowsDeleted']);
        $this->assertSame(1, $result['agentsDeleted']);
        $this->assertSame(1, $result['presetsDeleted']);
    }

    public function testIdempotentWhenNoSandbox(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->once())->method('flush');

        $workflowRepo = $this->createStub(SynapseWorkflowRepository::class);
        $workflowRepo->method('findSandbox')->willReturn([]);

        $runRepo = $this->createStub(SynapseWorkflowRunRepository::class);
        $runRepo->method('deleteByWorkflowKeys')->willReturn(0);

        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findSandbox')->willReturn([]);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findSandbox')->willReturn([]);

        $tool = new CleanupSandboxTool($em, $agentRepo, $workflowRepo, $runRepo, $presetRepo, $this->makeAdmin());
        $result = $tool();

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['agentsDeleted']);
        $this->assertSame(0, $result['workflowsDeleted']);
        $this->assertSame(0, $result['presetsDeleted']);
    }

    public function testDeniesAccessWithoutAdmin(): void
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(false);

        $tool = new CleanupSandboxTool(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(SynapseWorkflowRepository::class),
            $this->createStub(SynapseWorkflowRunRepository::class),
            $this->createStub(SynapseModelPresetRepository::class),
            $checker,
        );
        $result = $tool();

        $this->assertSame('error', $result['status']);
    }

    private function makeAdmin(): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(true);

        return $checker;
    }
}
