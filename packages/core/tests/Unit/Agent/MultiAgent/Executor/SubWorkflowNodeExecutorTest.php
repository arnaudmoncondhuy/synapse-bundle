<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\SubWorkflowNodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\SubWorkflowNodeExecutor
 */
final class SubWorkflowNodeExecutorTest extends TestCase
{
    public function testSupportsOnlySubWorkflow(): void
    {
        [$exec] = $this->makeExecutor();
        $this->assertTrue($exec->supports('sub_workflow'));
        $this->assertFalse($exec->supports('agent'));
        $this->assertFalse($exec->supports('parallel'));
    }

    public function testDelegatesToWorkflowRunner(): void
    {
        $targetWorkflow = new SynapseWorkflow();
        $targetWorkflow->setWorkflowKey('nested_wf');
        $targetWorkflow->setName('Nested');

        $repository = $this->createMock(SynapseWorkflowRepository::class);
        $repository->expects($this->once())
            ->method('findActiveByKey')
            ->with('nested_wf')
            ->willReturn($targetWorkflow);

        $expectedOutput = new Output(answer: 'from sub', data: ['result' => 42]);

        $runner = $this->createMock(WorkflowRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function (SynapseWorkflow $wf, Input $input) use ($targetWorkflow, $expectedOutput): Output {
                $this->assertSame($targetWorkflow, $wf);
                $this->assertSame(['message' => 'hello sub'], $input->getStructured());

                return $expectedOutput;
            });

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with(WorkflowRunner::class)->willReturn($runner);

        $exec = new SubWorkflowNodeExecutor($repository, $locator);

        $output = $exec->execute(
            step: ['name' => 'sw', 'type' => 'sub_workflow', 'workflow_key' => 'nested_wf'],
            resolvedInput: ['message' => 'hello sub'],
            state: ['inputs' => [], 'steps' => []],
            childContext: AgentContext::root(),
        );

        $this->assertSame('from sub', $output->getAnswer());
        $this->assertSame(42, $output->getData()['result']);
    }

    public function testWorkflowNotFoundRejected(): void
    {
        $repository = $this->createMock(SynapseWorkflowRepository::class);
        $repository->method('findActiveByKey')->willReturn(null);

        $exec = new SubWorkflowNodeExecutor($repository, $this->createStub(ContainerInterface::class));

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('not found or not active');

        $exec->execute(
            step: ['name' => 'sw', 'type' => 'sub_workflow', 'workflow_key' => 'ghost'],
            resolvedInput: [],
            state: ['steps' => []],
            childContext: AgentContext::root(),
        );
    }

    public function testMissingWorkflowKeyRejected(): void
    {
        [$exec] = $this->makeExecutor();

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('missing "workflow_key"');

        $exec->execute(
            step: ['name' => 'sw', 'type' => 'sub_workflow'],
            resolvedInput: [],
            state: ['steps' => []],
            childContext: AgentContext::root(),
        );
    }

    /**
     * @return array{SubWorkflowNodeExecutor, SynapseWorkflowRepository, WorkflowRunner, ContainerInterface}
     */
    private function makeExecutor(): array
    {
        $repository = $this->createStub(SynapseWorkflowRepository::class);
        $runner = $this->createStub(WorkflowRunner::class);
        $locator = $this->createStub(ContainerInterface::class);
        $locator->method('get')->willReturn($runner);

        return [new SubWorkflowNodeExecutor($repository, $locator), $repository, $runner, $locator];
    }
}
