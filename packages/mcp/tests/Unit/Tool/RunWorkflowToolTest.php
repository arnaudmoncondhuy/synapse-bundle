<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\RunWorkflowTool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\RunWorkflowTool
 */
class RunWorkflowToolTest extends TestCase
{
    public function testRunsWorkflowSuccessfully(): void
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('test_wf');
        $workflow->setIsActive(true);

        $output = new Output(
            answer: 'Final answer',
            data: ['result' => 'ok'],
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            metadata: ['workflow_run_id' => 'run-123', 'steps_executed' => 2],
        );

        $repo = $this->createStub(SynapseWorkflowRepository::class);
        $repo->method('findByKey')->willReturn($workflow);

        $runner = $this->createMock(WorkflowRunner::class);
        $runner->expects($this->once())->method('run')->willReturn($output);

        $resolver = $this->createStub(AgentResolver::class);
        $resolver->method('createRootContext')->willReturn(
            \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext::root(origin: 'mcp')
        );

        $tool = new RunWorkflowTool($runner, $repo, $resolver, $this->makeAdmin());
        $result = $tool('test_wf', message: 'Hello');

        $this->assertSame('success', $result['status']);
        $this->assertSame('run-123', $result['workflowRunId']);
        $this->assertSame(2, $result['stepsExecuted']);
        $this->assertSame(['result' => 'ok'], $result['outputs']);
        $this->assertSame(15, $result['usage']['total_tokens']);
    }

    public function testReturnsErrorWhenWorkflowNotFound(): void
    {
        $repo = $this->createStub(SynapseWorkflowRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $tool = new RunWorkflowTool(
            $this->createStub(WorkflowRunner::class),
            $repo,
            $this->createStub(AgentResolver::class),
            $this->makeAdmin(),
        );
        $result = $tool('nonexistent');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testReturnsErrorOnExecutionFailure(): void
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('fail_wf');
        $workflow->setIsActive(true);

        $repo = $this->createStub(SynapseWorkflowRepository::class);
        $repo->method('findByKey')->willReturn($workflow);

        $runner = $this->createStub(WorkflowRunner::class);
        $runner->method('run')->willThrowException(
            WorkflowExecutionException::stepFailed('analyze', 'LLM timeout')
        );

        $resolver = $this->createStub(AgentResolver::class);
        $resolver->method('createRootContext')->willReturn(
            \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext::root(origin: 'mcp')
        );

        $tool = new RunWorkflowTool($runner, $repo, $resolver, $this->makeAdmin());
        $result = $tool('fail_wf', message: 'test');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('analyze', $result['error']);
    }

    private function makeAdmin(): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(true);

        return $checker;
    }
}
