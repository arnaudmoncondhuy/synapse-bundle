<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalFailedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalReachedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalReachedEvent
 * @covers \ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalFailedEvent
 */
final class SynapseGoalLifecycleEventsTest extends TestCase
{
    public function testGoalReachedEventExposesAllFields(): void
    {
        $goal = new Goal(
            description: 'Trouver le meilleur restaurant italien',
            successCriteria: ['Au moins 3 candidats', 'Un recommandé avec justification'],
        );

        $event = new SynapseGoalReachedEvent(
            plannerName: 'demo_planner',
            goal: $goal,
            iterations: 2,
            totalUsage: ['prompt_tokens' => 150, 'completion_tokens' => 75, 'total_tokens' => 225],
        );

        $this->assertSame('demo_planner', $event->plannerName);
        $this->assertSame(2, $event->iterations);
        $this->assertSame($goal, $event->goal);
        $this->assertSame(225, $event->totalUsage['total_tokens']);
    }

    public function testGoalReachedToArraySerialization(): void
    {
        $goal = Goal::of('test goal');
        $event = new SynapseGoalReachedEvent(
            plannerName: 'p',
            goal: $goal,
            iterations: 1,
            totalUsage: ['total_tokens' => 10],
        );

        $arr = $event->toArray();
        $this->assertSame('p', $arr['planner_name']);
        $this->assertSame(1, $arr['iterations']);
        $this->assertSame('test goal', $arr['goal']['description']);
        $this->assertSame(10, $arr['total_usage']['total_tokens']);
    }

    public function testGoalFailedEventWithReasonAndMessage(): void
    {
        $goal = Goal::of('goal impossible');
        $event = new SynapseGoalFailedEvent(
            plannerName: 'planner',
            goal: $goal,
            iterations: 3,
            reason: 'max_iterations',
            errorMessage: 'Goal non atteint après 3 itérations',
            totalUsage: ['total_tokens' => 500],
        );

        $this->assertSame('max_iterations', $event->reason);
        $this->assertSame('Goal non atteint après 3 itérations', $event->errorMessage);
        $this->assertSame(3, $event->iterations);
    }

    public function testGoalFailedToArray(): void
    {
        $event = new SynapseGoalFailedEvent(
            plannerName: 'p',
            goal: Goal::of('x'),
            iterations: 1,
            reason: 'budget_exceeded',
            errorMessage: 'Budget 1 EUR exceeded',
        );

        $arr = $event->toArray();
        $this->assertSame('budget_exceeded', $arr['reason']);
        $this->assertSame('Budget 1 EUR exceeded', $arr['error_message']);
        $this->assertSame([], $arr['total_usage']);
    }

    public function testBothEventsAreImmutable(): void
    {
        $reached = new SynapseGoalReachedEvent('p', Goal::of('x'), 1);
        $failed = new SynapseGoalFailedEvent('p', Goal::of('x'), 1, 'max_iterations');

        foreach ([$reached, $failed] as $event) {
            $reflection = new \ReflectionClass($event);
            foreach ($reflection->getProperties() as $prop) {
                $this->assertTrue($prop->isReadOnly(), sprintf('%s::%s should be readonly', $reflection->getShortName(), $prop->getName()));
            }
        }
    }

    public function testGoalFailedAcceptsAllFourReasons(): void
    {
        foreach (['max_iterations', 'budget_exceeded', 'empty_plan', 'execution_failed'] as $reason) {
            $event = new SynapseGoalFailedEvent('p', Goal::of('x'), 1, $reason);
            $this->assertSame($reason, $event->reason);
        }
    }
}
