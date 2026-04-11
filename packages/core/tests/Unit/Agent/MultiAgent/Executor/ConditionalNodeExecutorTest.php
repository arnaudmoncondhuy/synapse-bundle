<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\ConditionalNodeExecutor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\ConditionalNodeExecutor
 */
final class ConditionalNodeExecutorTest extends TestCase
{
    public function testSupportsOnlyConditionalType(): void
    {
        $exec = new ConditionalNodeExecutor();
        $this->assertTrue($exec->supports('conditional'));
        $this->assertFalse($exec->supports('agent'));
        $this->assertFalse($exec->supports(''));
        $this->assertFalse($exec->supports('parallel'));
    }

    public function testEvaluatesJsonPathExpressionTruthy(): void
    {
        $exec = new ConditionalNodeExecutor();

        $state = [
            'inputs' => [],
            'steps' => [
                'classify' => [
                    'output' => ['data' => ['priority' => 'urgent']],
                ],
            ],
        ];

        $output = $exec->execute(
            step: [
                'name' => 'is_urgent',
                'type' => 'conditional',
                'condition' => '$.steps.classify.output.data.priority',
                'equals' => 'urgent',
            ],
            resolvedInput: [],
            state: $state,
            childContext: $this->ctx(),
        );

        $this->assertSame(['matched' => true, 'value' => 'urgent'], $output->getData());
        // Pas de tokens — le step ne parle à aucun LLM.
        $this->assertSame(0, $output->getUsage()['total_tokens']);
        $this->assertNull($output->getAnswer());
    }

    public function testEvaluatesJsonPathExpressionNotMatching(): void
    {
        $exec = new ConditionalNodeExecutor();

        $state = [
            'steps' => [
                'classify' => [
                    'output' => ['data' => ['priority' => 'low']],
                ],
            ],
        ];

        $output = $exec->execute(
            ['name' => 'is_urgent', 'type' => 'conditional', 'condition' => '$.steps.classify.output.data.priority', 'equals' => 'urgent'],
            [],
            $state,
            $this->ctx(),
        );

        $this->assertFalse($output->getData()['matched']);
        $this->assertSame('low', $output->getData()['value']);
    }

    public function testTruthyCheckWithoutEqualsClause(): void
    {
        $exec = new ConditionalNodeExecutor();

        $state = [
            'steps' => ['prev' => ['output' => ['data' => ['ok' => true]]]],
        ];

        $output = $exec->execute(
            ['name' => 'check', 'type' => 'conditional', 'condition' => '$.steps.prev.output.data.ok'],
            [],
            $state,
            $this->ctx(),
        );

        $this->assertTrue($output->getData()['matched']);
    }

    public function testTruthyCheckFalseOnNullValue(): void
    {
        $exec = new ConditionalNodeExecutor();

        $output = $exec->execute(
            ['name' => 'check', 'type' => 'conditional', 'condition' => '$.steps.missing.output.data.flag'],
            [],
            ['steps' => []],
            $this->ctx(),
        );

        $this->assertFalse($output->getData()['matched']);
        $this->assertNull($output->getData()['value']);
    }

    public function testLiteralStringValueWithEquals(): void
    {
        $exec = new ConditionalNodeExecutor();

        $output = $exec->execute(
            ['name' => 'check', 'type' => 'conditional', 'condition' => 'hello', 'equals' => 'hello'],
            [],
            [],
            $this->ctx(),
        );

        $this->assertTrue($output->getData()['matched']);
        $this->assertSame('hello', $output->getData()['value']);
    }

    public function testMissingConditionThrows(): void
    {
        $this->expectException(WorkflowExecutionException::class);
        $this->expectExceptionMessage('conditional step "broken" has no "condition"');

        (new ConditionalNodeExecutor())->execute(
            ['name' => 'broken', 'type' => 'conditional'],
            [],
            [],
            $this->ctx(),
        );
    }

    private function ctx(): AgentContext
    {
        return AgentContext::root(userId: null, origin: 'test');
    }
}
