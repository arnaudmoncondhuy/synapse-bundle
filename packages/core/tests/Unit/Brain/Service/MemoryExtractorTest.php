<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\ExtractionResult;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\NeuronExtractorInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\MemoryExtractor;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MemoryExtractorTest extends TestCase
{
    /**
     * Crée un extracteur stub qui annonce supporter les aires données et
     * retourne un ExtractionResult vide quand sollicité.
     *
     * @param list<BrainArea> $supportedAreas
     */
    private function stubExtractor(array $supportedAreas): NeuronExtractorInterface
    {
        $stub = $this->createStub(NeuronExtractorInterface::class);
        $stub->method('supportedAreas')->willReturn($supportedAreas);
        $stub->method('extract')->willReturnCallback(
            static fn (MemorySource $s, BrainArea $a): ExtractionResult => ExtractionResult::empty($a),
        );

        return $stub;
    }

    public function testDispatchesToExtractorByArea(): void
    {
        $semanticExtractor = $this->createMock(NeuronExtractorInterface::class);
        $semanticExtractor->method('supportedAreas')->willReturn([BrainArea::Semantic]);
        $semanticExtractor->expects($this->once())
            ->method('extract')
            ->willReturn(new ExtractionResult(BrainArea::Semantic, []));

        $episodicExtractor = $this->createStub(NeuronExtractorInterface::class);
        $episodicExtractor->method('supportedAreas')->willReturn([BrainArea::Episodic]);

        $orchestrator = new MemoryExtractor([$semanticExtractor, $episodicExtractor]);
        $source = new MemorySource('manual', []);

        $result = $orchestrator->extract($source, BrainArea::Semantic);

        $this->assertSame(BrainArea::Semantic, $result->area);
    }

    public function testThrowsWhenNoExtractorForArea(): void
    {
        $semantic = $this->stubExtractor([BrainArea::Semantic]);
        $orchestrator = new MemoryExtractor([$semantic]);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/no extractor registered for area "episodic"/');

        $orchestrator->extract($source, BrainArea::Episodic);
    }

    public function testSupportedAreasReflectsRegistration(): void
    {
        $semantic = $this->stubExtractor([BrainArea::Semantic]);
        $episodic = $this->stubExtractor([BrainArea::Episodic]);
        $encyclopedic = $this->stubExtractor([BrainArea::Encyclopedic]);

        $orchestrator = new MemoryExtractor([$semantic, $episodic, $encyclopedic]);

        $areas = $orchestrator->supportedAreas();

        $this->assertContains(BrainArea::Semantic, $areas);
        $this->assertContains(BrainArea::Episodic, $areas);
        $this->assertContains(BrainArea::Encyclopedic, $areas);
        $this->assertCount(3, $areas);
    }

    public function testEmptyExtractorListProducesNoSupportedAreas(): void
    {
        $orchestrator = new MemoryExtractor([]);

        $this->assertSame([], $orchestrator->supportedAreas());
    }

    public function testExtractorSupportingMultipleAreasIsRegisteredOnAll(): void
    {
        $multi = $this->stubExtractor([BrainArea::Semantic, BrainArea::Episodic]);
        $orchestrator = new MemoryExtractor([$multi]);
        $source = new MemorySource('manual', []);

        // Le même extracteur peut être appelé pour les 2 aires sans erreur
        $result1 = $orchestrator->extract($source, BrainArea::Semantic);
        $result2 = $orchestrator->extract($source, BrainArea::Episodic);

        $this->assertSame(BrainArea::Semantic, $result1->area);
        $this->assertSame(BrainArea::Episodic, $result2->area);
    }

    public function testLastExtractorWinsOnConflict(): void
    {
        // Deux extracteurs déclarent la même aire — le 2ème écrase (boot order)
        $first = $this->createStub(NeuronExtractorInterface::class);
        $first->method('supportedAreas')->willReturn([BrainArea::Semantic]);
        $first->method('extract')->willReturn(
            new ExtractionResult(BrainArea::Semantic, [], ['from' => 'first']),
        );

        $second = $this->createStub(NeuronExtractorInterface::class);
        $second->method('supportedAreas')->willReturn([BrainArea::Semantic]);
        $second->method('extract')->willReturn(
            new ExtractionResult(BrainArea::Semantic, [], ['from' => 'second']),
        );

        $orchestrator = new MemoryExtractor([$first, $second]);
        $source = new MemorySource('manual', []);

        $result = $orchestrator->extract($source, BrainArea::Semantic);

        // Le second extracteur a écrasé le premier dans extractorsByArea
        $this->assertSame('second', $result->debug['from']);
    }

    public function testCollisionTriggersLoggerWarning(): void
    {
        $first = $this->stubExtractor([BrainArea::Semantic]);
        $second = $this->stubExtractor([BrainArea::Semantic]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('collision sur l\'aire "semantic"'));

        new MemoryExtractor([$first, $second], $logger);
    }

    public function testNoWarningWhenNoCollision(): void
    {
        $semantic = $this->stubExtractor([BrainArea::Semantic]);
        $episodic = $this->stubExtractor([BrainArea::Episodic]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        new MemoryExtractor([$semantic, $episodic], $logger);
    }

    public function testSupportedAreasIsCached(): void
    {
        // Vérifie que les 2 appels successifs retournent le même tableau
        // (pas reconstruit à chaque fois)
        $semantic = $this->stubExtractor([BrainArea::Semantic]);
        $episodic = $this->stubExtractor([BrainArea::Episodic]);

        $orchestrator = new MemoryExtractor([$semantic, $episodic]);

        $first = $orchestrator->supportedAreas();
        $second = $orchestrator->supportedAreas();

        $this->assertSame($first, $second);
    }
}
