<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Convergence;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\CosineSimilarity;
use PHPUnit\Framework\TestCase;

class CosineSimilarityTest extends TestCase
{
    public function testIdenticalVectorsReturnOne(): void
    {
        $a = [1.0, 2.0, 3.0];

        $this->assertEqualsWithDelta(1.0, CosineSimilarity::compute($a, $a), 0.0001);
    }

    public function testOrthogonalVectorsReturnZero(): void
    {
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];

        $this->assertEqualsWithDelta(0.0, CosineSimilarity::compute($a, $b), 0.0001);
    }

    public function testOppositeVectorsReturnMinusOne(): void
    {
        $a = [1.0, 2.0, 3.0];
        $b = [-1.0, -2.0, -3.0];

        $this->assertEqualsWithDelta(-1.0, CosineSimilarity::compute($a, $b), 0.0001);
    }

    public function testProportionalVectorsReturnOne(): void
    {
        $a = [1.0, 2.0, 3.0];
        $b = [2.0, 4.0, 6.0];

        $this->assertEqualsWithDelta(1.0, CosineSimilarity::compute($a, $b), 0.0001);
    }

    public function testEmptyVectorReturnsZero(): void
    {
        $this->assertSame(0.0, CosineSimilarity::compute([], [1.0, 2.0]));
        $this->assertSame(0.0, CosineSimilarity::compute([1.0, 2.0], []));
        $this->assertSame(0.0, CosineSimilarity::compute([], []));
    }

    public function testZeroNormVectorReturnsZero(): void
    {
        $zero = [0.0, 0.0, 0.0];
        $a = [1.0, 2.0, 3.0];

        $this->assertSame(0.0, CosineSimilarity::compute($zero, $a));
        $this->assertSame(0.0, CosineSimilarity::compute($a, $zero));
    }

    public function testDimensionMismatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/same dimension/');

        CosineSimilarity::compute([1.0, 2.0], [1.0, 2.0, 3.0]);
    }

    public function testKnownIntermediateSimilarity(): void
    {
        // Vecteurs partiellement alignés
        $a = [1.0, 1.0];
        $b = [1.0, 0.0];

        // cos(angle) = (1*1 + 1*0) / (sqrt(2) * sqrt(1)) = 1/sqrt(2) ≈ 0.7071
        $this->assertEqualsWithDelta(0.7071, CosineSimilarity::compute($a, $b), 0.001);
    }
}
