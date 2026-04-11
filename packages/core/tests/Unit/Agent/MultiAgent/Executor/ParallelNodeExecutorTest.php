<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\ConditionalNodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\NodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\ParallelNodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\ParallelNodeExecutor
 */
final class ParallelNodeExecutorTest extends TestCase
{
    public function testSupportsOnlyParallel(): void
    {
        $exec = new ParallelNodeExecutor([]);
        $this->assertTrue($exec->supports('parallel'));
        $this->assertFalse($exec->supports('agent'));
        $this->assertFalse($exec->supports('loop'));
    }

    public function testExecutesAllBranchesAndMergesOutputs(): void
    {
        $spy = $this->makeSpyExecutor('agent', function (array $step): Output {
            return new Output(
                answer: 'out_'.$step['name'],
                data: ['step_name' => $step['name']],
                usage: ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            );
        });

        $parallel = new ParallelNodeExecutor([$spy]);

        $output = $parallel->execute(
            step: [
                'name' => 'fanout',
                'type' => 'parallel',
                'branches' => [
                    ['name' => 'b1', 'agent_name' => 'worker_a'],
                    ['name' => 'b2', 'agent_name' => 'worker_b'],
                    ['name' => 'b3', 'agent_name' => 'worker_c'],
                ],
            ],
            resolvedInput: [],
            state: ['inputs' => ['message' => 'hello'], 'steps' => []],
            childContext: $this->ctx(),
        );

        $branches = $output->getData()['branches'];
        $this->assertCount(3, $branches);
        $this->assertSame('out_b1', $branches['b1']['text']);
        $this->assertSame('out_b2', $branches['b2']['text']);
        $this->assertSame('out_b3', $branches['b3']['text']);
        $this->assertSame('b1', $branches['b1']['data']['step_name']);

        // Usage cumulé 3 × 7 = 21
        $this->assertSame(21, $output->getUsage()['total_tokens']);
    }

    public function testMixedBranchTypes(): void
    {
        // Un spy agent + le vrai ConditionalNodeExecutor
        $spy = $this->makeSpyExecutor('agent', fn () => new Output(answer: 'ok', usage: []));
        $parallel = new ParallelNodeExecutor([$spy, new ConditionalNodeExecutor()]);

        $output = $parallel->execute(
            step: [
                'name' => 'mixed',
                'type' => 'parallel',
                'branches' => [
                    ['name' => 'agent_branch', 'agent_name' => 'worker'],
                    [
                        'name' => 'cond_branch',
                        'type' => 'conditional',
                        'condition' => '$.inputs.priority',
                        'equals' => 'high',
                    ],
                ],
            ],
            resolvedInput: [],
            state: ['inputs' => ['priority' => 'high'], 'steps' => []],
            childContext: $this->ctx(),
        );

        $branches = $output->getData()['branches'];
        $this->assertSame('ok', $branches['agent_branch']['text']);
        $this->assertTrue($branches['cond_branch']['data']['matched']);
    }

    public function testBranchPropagatesErrorFromSubExecutor(): void
    {
        $failingSpy = $this->makeSpyExecutor('agent', function (): Output {
            throw new \RuntimeException('branch exploded');
        });
        $parallel = new ParallelNodeExecutor([$failingSpy]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('branch exploded');

        $parallel->execute(
            step: ['name' => 'p', 'type' => 'parallel', 'branches' => [['name' => 'b', 'agent_name' => 'w']]],
            resolvedInput: [],
            state: ['inputs' => [], 'steps' => []],
            childContext: $this->ctx(),
        );
    }

    public function testEmptyBranchesRejected(): void
    {
        $parallel = new ParallelNodeExecutor([]);
        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('empty or missing "branches"');

        $parallel->execute(
            step: ['name' => 'p', 'type' => 'parallel', 'branches' => []],
            resolvedInput: [],
            state: ['steps' => []],
            childContext: $this->ctx(),
        );
    }

    public function testUnknownBranchTypeRejected(): void
    {
        $spy = $this->makeSpyExecutor('agent', fn () => new Output(answer: 'x'));
        $parallel = new ParallelNodeExecutor([$spy]);

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('unknown type');

        $parallel->execute(
            step: [
                'name' => 'p',
                'type' => 'parallel',
                'branches' => [['name' => 'b', 'type' => 'martian', 'agent_name' => 'w']],
            ],
            resolvedInput: [],
            state: ['steps' => []],
            childContext: $this->ctx(),
        );
    }

    /**
     * @param callable(array<string, mixed>): Output $handler
     */
    private function makeSpyExecutor(string $supportedType, callable $handler): NodeExecutorInterface
    {
        return new class($supportedType, $handler) implements NodeExecutorInterface {
            /**
             * @param callable(array<string, mixed>): Output $handler
             */
            public function __construct(
                private readonly string $supportedType,
                private readonly mixed $handler,
            ) {
            }

            public function supports(string $type): bool
            {
                return $type === $this->supportedType;
            }

            public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output
            {
                return ($this->handler)($step);
            }
        };
    }

    private function ctx(): AgentContext
    {
        return AgentContext::root(userId: null, origin: 'test');
    }
}
