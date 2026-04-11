<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal
 */
final class GoalTest extends TestCase
{
    public function testOfCreatesSimpleGoal(): void
    {
        $goal = Goal::of('Trouver un restaurant italien');

        $this->assertSame('Trouver un restaurant italien', $goal->description);
        $this->assertSame([], $goal->successCriteria);
        $this->assertNull($goal->budget);
        $this->assertNull($goal->deadline);
        $this->assertSame([], $goal->metadata);
    }

    public function testFullConstruction(): void
    {
        $budget = new BudgetLimit(maxCostEur: 1.0);
        $deadline = new \DateTimeImmutable('2026-12-31 23:59:59');
        $goal = new Goal(
            description: 'Objectif complexe',
            successCriteria: ['critère 1', 'critère 2'],
            budget: $budget,
            deadline: $deadline,
            metadata: ['priority' => 'high'],
        );

        $this->assertSame('Objectif complexe', $goal->description);
        $this->assertCount(2, $goal->successCriteria);
        $this->assertSame($budget, $goal->budget);
        $this->assertSame($deadline, $goal->deadline);
        $this->assertSame(['priority' => 'high'], $goal->metadata);
    }

    public function testToArrayIsSerializableWithBudget(): void
    {
        $budget = new BudgetLimit(maxCostEur: 2.5, maxDurationSeconds: 120);
        $goal = new Goal(
            description: 'Test',
            successCriteria: ['a', 'b'],
            budget: $budget,
            deadline: new \DateTimeImmutable('2026-06-01 10:00:00'),
        );

        $array = $goal->toArray();

        $this->assertSame('Test', $array['description']);
        $this->assertSame(['a', 'b'], $array['success_criteria']);
        $this->assertIsArray($array['budget']);
        $this->assertSame(2.5, $array['budget']['max_cost_eur']);
        $this->assertSame(120, $array['budget']['max_duration_seconds']);
        $this->assertStringStartsWith('2026-06-01', (string) $array['deadline']);
    }

    public function testToArrayWithNullBudgetAndDeadline(): void
    {
        $goal = Goal::of('Simple');
        $array = $goal->toArray();

        $this->assertNull($array['budget']);
        $this->assertNull($array['deadline']);
    }

    public function testToPromptBlockContainsDescriptionAndCriteria(): void
    {
        $goal = new Goal(
            description: 'Trouver le meilleur restaurant',
            successCriteria: ['Au moins 3 candidats', 'Avec notes > 4/5'],
        );

        $block = $goal->toPromptBlock();

        $this->assertStringContainsString('Objectif', $block);
        $this->assertStringContainsString('Trouver le meilleur restaurant', $block);
        $this->assertStringContainsString('Critères de succès', $block);
        $this->assertStringContainsString('1. Au moins 3 candidats', $block);
        $this->assertStringContainsString('2. Avec notes > 4/5', $block);
    }

    public function testToPromptBlockWithoutCriteriaIsMinimal(): void
    {
        $goal = Goal::of('Goal simple');
        $block = $goal->toPromptBlock();

        $this->assertStringContainsString('Goal simple', $block);
        $this->assertStringNotContainsString('Critères de succès', $block);
    }

    public function testToPromptBlockIncludesDeadlineAndBudget(): void
    {
        $goal = new Goal(
            description: 'Goal avec contraintes',
            budget: new BudgetLimit(maxCostEur: 1.5, maxDurationSeconds: 60),
            deadline: new \DateTimeImmutable('2027-01-15 10:00:00'),
        );

        $block = $goal->toPromptBlock();

        $this->assertStringContainsString('Échéance', $block);
        $this->assertStringContainsString('15/01/2027', $block);
        $this->assertStringContainsString('Budget', $block);
        $this->assertStringContainsString('1.50 EUR', $block);
        $this->assertStringContainsString('60s', $block);
    }
}
