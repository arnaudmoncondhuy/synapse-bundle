<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\ExtractionResult;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ExtractionResultTest extends TestCase
{
    public function testConstructorAssignsAllFields(): void
    {
        $area = BrainArea::Semantic;
        $neurons = [
            new SemanticNeuron(Uuid::v7(), 'X', 'is', 'matin'),
            new SemanticNeuron(Uuid::v7(), 'X', 'is', 'cool'),
        ];
        $debug = ['tokens' => 142, 'latency_ms' => 320];

        $result = new ExtractionResult($area, $neurons, $debug);

        $this->assertSame($area, $result->area);
        $this->assertSame($neurons, $result->neurons);
        $this->assertSame($debug, $result->debug);
    }

    public function testCountReturnsNumberOfNeurons(): void
    {
        $result = new ExtractionResult(
            BrainArea::Semantic,
            [
                new SemanticNeuron(Uuid::v7(), 'X', 'is', 'a'),
                new SemanticNeuron(Uuid::v7(), 'X', 'is', 'b'),
                new SemanticNeuron(Uuid::v7(), 'X', 'is', 'c'),
            ],
        );

        $this->assertSame(3, $result->count());
    }

    public function testIsEmptyOnEmptyResult(): void
    {
        $result = new ExtractionResult(BrainArea::Semantic, []);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->count());
    }

    public function testEmptyFactoryProducesEmpty(): void
    {
        $result = ExtractionResult::empty(BrainArea::Episodic);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(BrainArea::Episodic, $result->area);
        $this->assertSame([], $result->debug);
    }

    public function testDebugIsEmptyByDefault(): void
    {
        $result = new ExtractionResult(BrainArea::Semantic, []);

        $this->assertSame([], $result->debug);
    }
}
