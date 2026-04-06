<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxWorkflowTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxWorkflowTool
 */
class CreateSandboxWorkflowToolTest extends TestCase
{
    public function testCreatesWorkflowSuccessfully(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            fn (SynapseWorkflow $w) => $w->isSandbox()
                && 'test_wf' === $w->getWorkflowKey()
                && $w->isActive()
        ));
        $em->expects($this->once())->method('flush');

        $repo = $this->createStub(SynapseWorkflowRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $resolver = $this->createStub(AgentResolver::class);
        $resolver->method('has')->willReturn(true);

        $tool = new CreateSandboxWorkflowTool($em, $repo, $resolver, $this->makeAdmin());
        $definition = json_encode([
            'version' => 1,
            'steps' => [
                ['name' => 'step1', 'agent_name' => 'agent_a', 'input_mapping' => ['text' => '$.inputs.message']],
            ],
            'outputs' => ['result' => '$.steps.step1.output.text'],
        ]);
        $result = $tool('test_wf', 'Test Workflow', $definition);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['stepsCount']);
        $this->assertSame(['agent_a'], $result['agents']);
    }

    public function testRejectsInvalidJson(): void
    {
        $tool = $this->makeTool();
        $result = $tool('test', 'Test', '{invalid json');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid JSON', $result['error']);
    }

    public function testRejectsMissingSteps(): void
    {
        $tool = $this->makeTool();
        $result = $tool('test', 'Test', json_encode(['version' => 1]));

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('steps', $result['error']);
    }

    public function testRejectsUnresolvableAgent(): void
    {
        $repo = $this->createStub(SynapseWorkflowRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $resolver = $this->createStub(AgentResolver::class);
        $resolver->method('has')->willReturn(false);

        $tool = new CreateSandboxWorkflowTool(
            $this->createStub(EntityManagerInterface::class),
            $repo,
            $resolver,
            $this->makeAdmin(),
        );

        $definition = json_encode([
            'version' => 1,
            'steps' => [['name' => 's1', 'agent_name' => 'nonexistent']],
        ]);
        $result = $tool('test', 'Test', $definition);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not resolvable', $result['error']);
    }

    public function testRejectsDuplicateKey(): void
    {
        $repo = $this->createStub(SynapseWorkflowRepository::class);
        $repo->method('findByKey')->willReturn(new SynapseWorkflow());

        $resolver = $this->createStub(AgentResolver::class);
        $resolver->method('has')->willReturn(true);

        $tool = new CreateSandboxWorkflowTool(
            $this->createStub(EntityManagerInterface::class),
            $repo,
            $resolver,
            $this->makeAdmin(),
        );

        $definition = json_encode(['version' => 1, 'steps' => [['name' => 's1', 'agent_name' => 'a']]]);
        $result = $tool('existing', 'Test', $definition);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('already exists', $result['error']);
    }

    private function makeTool(): CreateSandboxWorkflowTool
    {
        return new CreateSandboxWorkflowTool(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseWorkflowRepository::class),
            $this->createStub(AgentResolver::class),
            $this->makeAdmin(),
        );
    }

    private function makeAdmin(): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(true);

        return $checker;
    }
}
