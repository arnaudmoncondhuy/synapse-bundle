<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator
 */
final class WorkflowDefinitionValidatorTest extends TestCase
{
    private WorkflowDefinitionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WorkflowDefinitionValidator();
    }

    public function testValidAgentOnlyWorkflow(): void
    {
        $definition = [
            'steps' => [
                ['name' => 's1', 'agent_name' => 'a1'],
                ['name' => 's2', 'agent_name' => 'a2', 'input_mapping' => ['x' => '$.steps.s1.output.text']],
            ],
            'outputs' => ['result' => '$.steps.s2.output.text'],
        ];
        $this->assertNull($this->validator->validate($definition));
    }

    public function testValidMixedWorkflow(): void
    {
        $definition = [
            'steps' => [
                ['name' => 'classify', 'agent_name' => 'clf'],
                ['name' => 'is_urgent', 'type' => 'conditional', 'condition' => '$.steps.classify.output.data.priority', 'equals' => 'urgent'],
                [
                    'name' => 'fanout',
                    'type' => 'parallel',
                    'branches' => [
                        ['name' => 'summary_fr', 'agent_name' => 'summarizer_fr'],
                        ['name' => 'summary_en', 'agent_name' => 'summarizer_en'],
                    ],
                ],
                [
                    'name' => 'per_doc',
                    'type' => 'loop',
                    'items_path' => '$.inputs.documents',
                    'step' => ['name' => 'process_one', 'agent_name' => 'processor'],
                ],
                ['name' => 'delegate', 'type' => 'sub_workflow', 'workflow_key' => 'nested_wf'],
            ],
        ];
        $this->assertNull($this->validator->validate($definition));
    }

    public function testStepsMissingRejected(): void
    {
        $this->assertStringContainsString('steps', (string) $this->validator->validate([]));
    }

    public function testStepsEmptyRejected(): void
    {
        $this->assertStringContainsString('vide', (string) $this->validator->validate(['steps' => []]));
    }

    public function testStepWithoutNameRejected(): void
    {
        $error = $this->validator->validate(['steps' => [['agent_name' => 'a1']]]);
        $this->assertStringContainsString('name', (string) $error);
    }

    public function testUnknownTypeRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 'weird', 'type' => 'my_type']],
        ]);
        $this->assertStringContainsString('inconnu', (string) $error);
        $this->assertStringContainsString('my_type', (string) $error);
    }

    public function testConditionalWithoutConditionRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 's', 'type' => 'conditional']],
        ]);
        $this->assertStringContainsString('condition', (string) $error);
    }

    public function testParallelWithoutBranchesRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 'p', 'type' => 'parallel']],
        ]);
        $this->assertStringContainsString('branches', (string) $error);
    }

    public function testParallelWithEmptyBranchesRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 'p', 'type' => 'parallel', 'branches' => []]],
        ]);
        $this->assertStringContainsString('branches', (string) $error);
    }

    public function testParallelWithDuplicateBranchNamesRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [
                [
                    'name' => 'p',
                    'type' => 'parallel',
                    'branches' => [
                        ['name' => 'b1', 'agent_name' => 'a1'],
                        ['name' => 'b1', 'agent_name' => 'a2'],
                    ],
                ],
            ],
        ]);
        $this->assertStringContainsString('dupliqué', (string) $error);
    }

    public function testLoopWithoutItemsPathRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 'l', 'type' => 'loop', 'step' => ['name' => 't', 'agent_name' => 'a']]],
        ]);
        $this->assertStringContainsString('items_path', (string) $error);
    }

    public function testLoopWithoutTemplateStepRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 'l', 'type' => 'loop', 'items_path' => '$.inputs.items']],
        ]);
        $this->assertStringContainsString('step', (string) $error);
    }

    public function testSubWorkflowWithoutKeyRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [['name' => 'sw', 'type' => 'sub_workflow']],
        ]);
        $this->assertStringContainsString('workflow_key', (string) $error);
    }

    public function testDanglingReferenceRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [
                ['name' => 's1', 'agent_name' => 'a1', 'input_mapping' => ['x' => '$.steps.s99.output.text']],
            ],
        ]);
        $this->assertStringContainsString('inexistant', (string) $error);
        $this->assertStringContainsString('s99', (string) $error);
    }

    public function testDuplicateStepNameRejected(): void
    {
        $error = $this->validator->validate([
            'steps' => [
                ['name' => 's1', 'agent_name' => 'a1'],
                ['name' => 's1', 'agent_name' => 'a2'],
            ],
        ]);
        $this->assertStringContainsString('dupliqué', (string) $error);
    }
}
