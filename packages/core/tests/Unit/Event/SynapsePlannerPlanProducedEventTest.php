<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\SynapsePlannerPlanProducedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Event\SynapsePlannerPlanProducedEvent
 */
final class SynapsePlannerPlanProducedEventTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $goal = Goal::of('Test goal');
        $plan = Plan::fromArray([
            'reasoning' => 'Because',
            'steps' => [['name' => 's1', 'agent_name' => 'redacteur']],
        ]);

        $event = new SynapsePlannerPlanProducedEvent(
            plannerName: 'demo_planner',
            goal: $goal,
            plan: $plan,
            workflowRunId: 'abc-123',
            ephemeralWorkflowKey: 'planner_demo_1',
        );

        $this->assertSame('demo_planner', $event->plannerName);
        $this->assertSame($goal, $event->goal);
        $this->assertSame($plan, $event->plan);
        $this->assertSame('abc-123', $event->workflowRunId);
        $this->assertSame('planner_demo_1', $event->ephemeralWorkflowKey);
    }

    public function testConstructionWithOptionalsNull(): void
    {
        $event = new SynapsePlannerPlanProducedEvent(
            plannerName: 'demo_planner',
            goal: Goal::of('T'),
            plan: Plan::fromArray(['reasoning' => 'R', 'steps' => [['name' => 'a', 'agent_name' => 'b']]]),
        );

        $this->assertNull($event->workflowRunId);
        $this->assertNull($event->ephemeralWorkflowKey);
    }

    public function testToArrayHasExpectedTopLevelKeys(): void
    {
        $event = new SynapsePlannerPlanProducedEvent(
            plannerName: 'demo_planner',
            goal: Goal::of('Test'),
            plan: Plan::fromArray(['reasoning' => 'R', 'steps' => [['name' => 'a', 'agent_name' => 'b', 'rationale' => 'r']]]),
            workflowRunId: 'run-1',
            ephemeralWorkflowKey: 'wf_1',
        );

        $array = $event->toArray();

        $this->assertSame('demo_planner', $array['planner_name']);
        $this->assertSame('run-1', $array['workflow_run_id']);
        $this->assertSame('wf_1', $array['ephemeral_workflow_key']);
        $this->assertIsArray($array['goal']);
        $this->assertIsArray($array['plan']);
    }

    public function testToArrayGoalIsFlattenedForJsStreaming(): void
    {
        $event = new SynapsePlannerPlanProducedEvent(
            plannerName: 'p',
            goal: new Goal(
                description: 'Find a cat',
                successCriteria: ['cat is found', 'cat is alive'],
            ),
            plan: Plan::fromArray(['reasoning' => 'R', 'steps' => [['name' => 'a', 'agent_name' => 'b']]]),
        );

        $array = $event->toArray();

        // Le goal est sérialisé en shape minimale pour le front, pas toArray() complet
        $this->assertSame('Find a cat', $array['goal']['description']);
        $this->assertSame(['cat is found', 'cat is alive'], $array['goal']['success_criteria']);
        $this->assertArrayNotHasKey('budget', $array['goal']);
        $this->assertArrayNotHasKey('deadline', $array['goal']);
    }

    public function testToArrayPlanStripsInternalFields(): void
    {
        $event = new SynapsePlannerPlanProducedEvent(
            plannerName: 'p',
            goal: Goal::of('T'),
            plan: Plan::fromArray([
                'reasoning' => 'Because',
                'steps' => [
                    [
                        'name' => 'step1',
                        'agent_name' => 'redacteur',
                        'input_mapping' => ['message' => '$.inputs.topic'],
                        'output_key' => 'final',
                        'rationale' => 'Explain why',
                    ],
                ],
                'outputs' => ['done' => '$.steps.step1.output.text'],
            ], iteration: 2),
        );

        $array = $event->toArray();

        $this->assertSame(2, $array['plan']['iteration']);
        $this->assertSame('Because', $array['plan']['reasoning']);
        $this->assertCount(1, $array['plan']['steps']);

        // Les steps dans le payload NDJSON ne contiennent que les 3 champs
        // utiles au front : name, agent_name, rationale. Pas input_mapping
        // (trop technique) ni output_key (redondant avec name).
        $step = $array['plan']['steps'][0];
        $this->assertArrayHasKey('name', $step);
        $this->assertArrayHasKey('agent_name', $step);
        $this->assertArrayHasKey('rationale', $step);
        $this->assertArrayNotHasKey('input_mapping', $step);
        $this->assertArrayNotHasKey('output_key', $step);

        // Les outputs sont conservés tels quels
        $this->assertSame(['done' => '$.steps.step1.output.text'], $array['plan']['outputs']);
    }

    public function testToArrayIsJsonSerializable(): void
    {
        $event = new SynapsePlannerPlanProducedEvent(
            plannerName: 'p',
            goal: Goal::of('T'),
            plan: Plan::fromArray(['reasoning' => 'R', 'steps' => [['name' => 'a', 'agent_name' => 'b', 'rationale' => 'r']]]),
        );

        $json = json_encode($event->toArray(), \JSON_THROW_ON_ERROR);
        $this->assertIsString($json);

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('p', $decoded['planner_name']);
    }
}
