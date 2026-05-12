<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Enum;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrainAreaTest extends TestCase
{
    public function testHasSevenAreas(): void
    {
        $this->assertCount(7, BrainArea::cases());
    }

    public function testAssociationAreasContainsFive(): void
    {
        $association = BrainArea::associationAreas();

        $this->assertCount(5, $association);
        $this->assertContains(BrainArea::Episodic, $association);
        $this->assertContains(BrainArea::Semantic, $association);
        $this->assertContains(BrainArea::Encyclopedic, $association);
        $this->assertContains(BrainArea::Procedural, $association);
        $this->assertContains(BrainArea::Emotional, $association);
    }

    public function testTransductionAreasContainsTwo(): void
    {
        $transduction = BrainArea::transductionAreas();

        $this->assertCount(2, $transduction);
        $this->assertContains(BrainArea::Sensory, $transduction);
        $this->assertContains(BrainArea::Motor, $transduction);
    }

    public function testAssociationAndTransductionArePartition(): void
    {
        $association = BrainArea::associationAreas();
        $transduction = BrainArea::transductionAreas();

        $merged = array_merge($association, $transduction);

        $this->assertCount(7, $merged);
        $this->assertEquals(BrainArea::cases(), $merged);
    }

    #[DataProvider('associationProvider')]
    public function testIsAssociation(BrainArea $area): void
    {
        $this->assertTrue($area->isAssociation());
        $this->assertFalse($area->isTransduction());
    }

    #[DataProvider('transductionProvider')]
    public function testIsTransduction(BrainArea $area): void
    {
        $this->assertTrue($area->isTransduction());
        $this->assertFalse($area->isAssociation());
    }

    /**
     * @return iterable<string, array{BrainArea}>
     */
    public static function associationProvider(): iterable
    {
        yield 'episodic' => [BrainArea::Episodic];
        yield 'semantic' => [BrainArea::Semantic];
        yield 'encyclopedic' => [BrainArea::Encyclopedic];
        yield 'procedural' => [BrainArea::Procedural];
        yield 'emotional' => [BrainArea::Emotional];
    }

    /**
     * @return iterable<string, array{BrainArea}>
     */
    public static function transductionProvider(): iterable
    {
        yield 'sensory' => [BrainArea::Sensory];
        yield 'motor' => [BrainArea::Motor];
    }
}
