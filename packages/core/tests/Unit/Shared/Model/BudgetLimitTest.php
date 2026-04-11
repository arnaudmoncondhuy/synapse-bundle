<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit
 */
final class BudgetLimitTest extends TestCase
{
    public function testUnlimitedHasNoViolation(): void
    {
        $budget = BudgetLimit::unlimited();

        $this->assertNull($budget->firstViolation(
            currentTokens: 100000,
            currentCostEur: 1000.0,
            currentDepth: 99,
            elapsedSeconds: 99999,
            planningIterations: 999,
        ));
    }

    public function testWithCostAndDuration(): void
    {
        $budget = BudgetLimit::withCostAndDuration(maxCostEur: 1.50, maxDurationSeconds: 60);

        $this->assertSame(1.50, $budget->maxCostEur);
        $this->assertSame(60, $budget->maxDurationSeconds);
        $this->assertNull($budget->maxTokens);
        $this->assertNull($budget->maxDepth);
        $this->assertNull($budget->maxPlanningIterations);
    }

    public function testMaxTokensViolation(): void
    {
        $budget = new BudgetLimit(maxTokens: 1000);

        $this->assertNull($budget->firstViolation(currentTokens: 500));
        $this->assertNotNull($budget->firstViolation(currentTokens: 1000));
        $this->assertStringContainsString('maxTokens', (string) $budget->firstViolation(currentTokens: 1500));
    }

    public function testMaxCostEurViolation(): void
    {
        $budget = new BudgetLimit(maxCostEur: 0.50);

        $this->assertNull($budget->firstViolation(currentCostEur: 0.25));
        $violation = $budget->firstViolation(currentCostEur: 0.50);
        $this->assertNotNull($violation);
        $this->assertStringContainsString('maxCostEur', $violation);
    }

    public function testMaxDepthViolation(): void
    {
        $budget = new BudgetLimit(maxDepth: 3);

        $this->assertNull($budget->firstViolation(currentDepth: 2));
        $this->assertNotNull($budget->firstViolation(currentDepth: 3));
        $this->assertStringContainsString('maxDepth', (string) $budget->firstViolation(currentDepth: 5));
    }

    public function testMaxDurationViolation(): void
    {
        $budget = new BudgetLimit(maxDurationSeconds: 30);

        $this->assertNull($budget->firstViolation(elapsedSeconds: 15));
        $this->assertNotNull($budget->firstViolation(elapsedSeconds: 30));
        $this->assertStringContainsString('maxDurationSeconds', (string) $budget->firstViolation(elapsedSeconds: 60));
    }

    public function testMaxPlanningIterationsViolation(): void
    {
        $budget = new BudgetLimit(maxPlanningIterations: 2);

        $this->assertNull($budget->firstViolation(planningIterations: 1));
        $this->assertNotNull($budget->firstViolation(planningIterations: 2));
    }

    public function testFirstViolationOrder(): void
    {
        // Quand plusieurs limites sont dépassées, la priorité de rapport
        // est documentée : tokens > cost > depth > duration > planning
        $budget = new BudgetLimit(
            maxTokens: 100,
            maxCostEur: 0.01,
            maxDepth: 1,
            maxDurationSeconds: 10,
            maxPlanningIterations: 1,
        );

        $violation = $budget->firstViolation(
            currentTokens: 200,
            currentCostEur: 1.0,
            currentDepth: 5,
            elapsedSeconds: 60,
            planningIterations: 10,
        );

        $this->assertNotNull($violation);
        $this->assertStringStartsWith('maxTokens', $violation);
    }

    public function testToArrayPreservesNullsForOmittedLimits(): void
    {
        $budget = new BudgetLimit(maxCostEur: 1.0);

        $array = $budget->toArray();

        $this->assertNull($array['max_tokens']);
        $this->assertSame(1.0, $array['max_cost_eur']);
        $this->assertNull($array['max_depth']);
        $this->assertNull($array['max_duration_seconds']);
        $this->assertNull($array['max_planning_iterations']);
    }

    public function testImmutability(): void
    {
        $budget = new BudgetLimit(maxTokens: 100);

        // Les propriétés sont readonly — toute tentative de modification
        // devrait lever une Error. On s'en assure.
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $budget->maxTokens = 200;
    }
}
