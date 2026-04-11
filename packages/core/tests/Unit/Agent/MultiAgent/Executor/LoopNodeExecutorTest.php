<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\LoopNodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\NodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\LoopNodeExecutor
 */
final class LoopNodeExecutorTest extends TestCase
{
    public function testSupportsOnlyLoop(): void
    {
        $exec = new LoopNodeExecutor([]);
        $this->assertTrue($exec->supports('loop'));
        $this->assertFalse($exec->supports('parallel'));
        $this->assertFalse($exec->supports('agent'));
    }

    public function testIteratesOverItemsAndCollectsOutputs(): void
    {
        $capturedItems = [];
        $spy = $this->makeSpyExecutor('agent', function (array $step, array $input) use (&$capturedItems): Output {
            $capturedItems[] = $input['text'] ?? null;

            return new Output(
                answer: 'processed_'.($input['text'] ?? 'x'),
                usage: ['prompt_tokens' => 3, 'completion_tokens' => 1, 'total_tokens' => 4],
            );
        });

        $loop = new LoopNodeExecutor([$spy]);

        $output = $loop->execute(
            step: [
                'name' => 'per_doc',
                'type' => 'loop',
                'items_path' => '$.inputs.docs',
                'step' => [
                    'name' => 'process_one',
                    'agent_name' => 'processor',
                    'input_mapping' => ['text' => '$.inputs.item'],
                ],
            ],
            resolvedInput: [],
            state: ['inputs' => ['docs' => ['doc1', 'doc2', 'doc3']], 'steps' => []],
            childContext: $this->ctx(),
        );

        $iterations = $output->getData()['iterations'];
        $this->assertCount(3, $iterations);
        $this->assertSame('doc1', $iterations[0]['item']);
        $this->assertSame('processed_doc1', $iterations[0]['output']['text']);
        $this->assertSame(['doc1', 'doc2', 'doc3'], $capturedItems);

        // Usage cumulé 3 × 4 = 12
        $this->assertSame(12, $output->getUsage()['total_tokens']);
    }

    public function testCustomItemAlias(): void
    {
        $captured = null;
        $spy = $this->makeSpyExecutor('agent', function (array $step, array $input) use (&$captured): Output {
            $captured = $input;

            return new Output(answer: 'ok');
        });
        $loop = new LoopNodeExecutor([$spy]);

        $loop->execute(
            step: [
                'name' => 'l',
                'type' => 'loop',
                'items_path' => '$.inputs.rows',
                'item_alias' => 'row',
                'step' => [
                    'name' => 't',
                    'agent_name' => 'processor',
                    'input_mapping' => ['data' => '$.inputs.row', 'idx' => '$.inputs.index'],
                ],
            ],
            resolvedInput: [],
            state: ['inputs' => ['rows' => [['a' => 1], ['a' => 2]]], 'steps' => []],
            childContext: $this->ctx(),
        );

        $this->assertSame(['a' => 2], $captured['data']);
        $this->assertSame(1, $captured['idx']);
    }

    public function testItemsPathNotArrayRejected(): void
    {
        $loop = new LoopNodeExecutor([$this->makeSpyExecutor('agent', fn () => new Output(answer: 'x'))]);

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('did not resolve to an array');

        $loop->execute(
            step: [
                'name' => 'l',
                'type' => 'loop',
                'items_path' => '$.inputs.not_an_array',
                'step' => ['name' => 't', 'agent_name' => 'a'],
            ],
            resolvedInput: [],
            state: ['inputs' => ['not_an_array' => 'just a string'], 'steps' => []],
            childContext: $this->ctx(),
        );
    }

    public function testMaxIterationsEnforced(): void
    {
        $loop = new LoopNodeExecutor([$this->makeSpyExecutor('agent', fn () => new Output(answer: 'x'))]);

        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('exceeds max_iterations');

        $loop->execute(
            step: [
                'name' => 'l',
                'type' => 'loop',
                'items_path' => '$.inputs.items',
                'max_iterations' => 2,
                'step' => ['name' => 't', 'agent_name' => 'a'],
            ],
            resolvedInput: [],
            state: ['inputs' => ['items' => [1, 2, 3]], 'steps' => []],
            childContext: $this->ctx(),
        );
    }

    public function testMissingItemsPathRejected(): void
    {
        $loop = new LoopNodeExecutor([]);
        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('missing "items_path"');

        $loop->execute(
            ['name' => 'l', 'type' => 'loop', 'step' => ['name' => 't', 'agent_name' => 'a']],
            [],
            ['steps' => []],
            $this->ctx(),
        );
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): Output $handler
     */
    private function makeSpyExecutor(string $supportedType, callable $handler): NodeExecutorInterface
    {
        return new class($supportedType, $handler) implements NodeExecutorInterface {
            /**
             * @param callable(array<string, mixed>, array<string, mixed>): Output $handler
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
                return ($this->handler)($step, $resolvedInput);
            }
        };
    }

    private function ctx(): AgentContext
    {
        return AgentContext::root(userId: null, origin: 'test');
    }
}
