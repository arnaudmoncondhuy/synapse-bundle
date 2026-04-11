<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan
 */
final class PlanTest extends TestCase
{
    public function testFromArrayHappyPath(): void
    {
        $data = [
            'reasoning' => 'Plan simple à 1 step',
            'steps' => [
                [
                    'name' => 'step1',
                    'agent_name' => 'redacteur',
                    'input_mapping' => ['message' => '$.inputs.topic'],
                    'output_key' => 'text',
                    'rationale' => 'Rédige le texte',
                ],
            ],
            'outputs' => [
                'final' => '$.steps.step1.output.text',
            ],
        ];

        $plan = Plan::fromArray($data, iteration: 2);

        $this->assertSame('Plan simple à 1 step', $plan->reasoning);
        $this->assertSame(2, $plan->iteration);
        $this->assertCount(1, $plan->steps);
        $this->assertSame('step1', $plan->steps[0]['name']);
        $this->assertSame('redacteur', $plan->steps[0]['agent_name']);
        $this->assertSame('Rédige le texte', $plan->steps[0]['rationale']);
        $this->assertSame(['final' => '$.steps.step1.output.text'], $plan->outputs);
    }

    public function testFromArrayFillsDefaultsForOptionalStepFields(): void
    {
        $data = [
            'reasoning' => 'Minimal',
            'steps' => [
                ['name' => 'a', 'agent_name' => 'redacteur'],
            ],
        ];

        $plan = Plan::fromArray($data);

        $this->assertSame('a', $plan->steps[0]['output_key']); // Default = name
        $this->assertSame([], $plan->steps[0]['input_mapping']); // Default = []
        $this->assertSame('', $plan->steps[0]['rationale']); // Default = ''
        $this->assertSame([], $plan->outputs);
        $this->assertSame(0, $plan->iteration);
    }

    public function testFromArrayRejectsNonStringReasoning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reasoning.*string/');

        Plan::fromArray([
            'reasoning' => ['not a string'],
            'steps' => [['name' => 'a', 'agent_name' => 'b']],
        ]);
    }

    public function testFromArrayRejectsEmptySteps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/steps.*non-empty/');

        Plan::fromArray([
            'reasoning' => 'Valid',
            'steps' => [],
        ]);
    }

    public function testFromArrayRejectsMissingAgentName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/agent_name/');

        Plan::fromArray([
            'reasoning' => 'Valid',
            'steps' => [['name' => 'a']],
        ]);
    }

    public function testFromArrayRejectsDuplicateStepNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');

        Plan::fromArray([
            'reasoning' => 'Valid',
            'steps' => [
                ['name' => 'same', 'agent_name' => 'a'],
                ['name' => 'same', 'agent_name' => 'b'],
            ],
        ]);
    }

    public function testToWorkflowDefinitionStripsNonPivotFields(): void
    {
        $plan = Plan::fromArray([
            'reasoning' => 'Test',
            'steps' => [
                [
                    'name' => 'step1',
                    'agent_name' => 'redacteur',
                    'rationale' => 'Explication hors format pivot',
                ],
            ],
            'outputs' => ['out' => '$.steps.step1.output.text'],
        ]);

        $def = $plan->toWorkflowDefinition();

        $this->assertSame(1, $def['version']);
        $this->assertCount(1, $def['steps']);

        // Le `rationale` doit être retiré pour compatibilité avec
        // WorkflowController::validatePivotStructure() qui ne le connaît pas.
        $this->assertArrayNotHasKey('rationale', $def['steps'][0]);
        $this->assertSame('step1', $def['steps'][0]['name']);
        $this->assertSame('redacteur', $def['steps'][0]['agent_name']);
        $this->assertSame(['out' => '$.steps.step1.output.text'], $def['outputs']);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = Plan::fromArray([
            'reasoning' => 'Round-trip test',
            'steps' => [
                ['name' => 'a', 'agent_name' => 'agent1', 'rationale' => 'Because.'],
                ['name' => 'b', 'agent_name' => 'agent2', 'rationale' => 'Then that.'],
            ],
            'outputs' => ['final' => '$.steps.b.output.text'],
        ], iteration: 1);

        $array = $original->toArray();

        $this->assertSame('Round-trip test', $array['reasoning']);
        $this->assertSame(1, $array['iteration']);
        $this->assertCount(2, $array['steps']);
        $this->assertArrayHasKey('final', $array['outputs']);
    }

    public function testStepsCount(): void
    {
        $plan = Plan::fromArray([
            'reasoning' => 'Three steps',
            'steps' => [
                ['name' => 'a', 'agent_name' => 'x'],
                ['name' => 'b', 'agent_name' => 'y'],
                ['name' => 'c', 'agent_name' => 'z'],
            ],
        ]);

        $this->assertSame(3, $plan->stepsCount());
    }

    public function testFromArrayRejectsNonObjectStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Plan::fromArray([
            'reasoning' => 'bad',
            'steps' => ['not an object'],
        ]);
    }
}
