<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\ExtractionResult;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\MultiAreaExtractorInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;

class MultiAreaExtractorInterfaceTest extends TestCase
{
    public function testStubImplementationRespectsContract(): void
    {
        $stub = new class implements MultiAreaExtractorInterface {
            public function supportedAreas(): array
            {
                return [BrainArea::Semantic, BrainArea::Episodic];
            }

            public function extractAll(MemorySource $source): array
            {
                return [
                    ExtractionResult::empty(BrainArea::Semantic),
                    ExtractionResult::empty(BrainArea::Episodic),
                ];
            }
        };

        $source = new MemorySource('manual', []);

        $this->assertSame(
            [BrainArea::Semantic, BrainArea::Episodic],
            $stub->supportedAreas(),
        );

        $results = $stub->extractAll($source);

        $this->assertCount(2, $results);
        $this->assertSame(BrainArea::Semantic, $results[0]->area);
        $this->assertSame(BrainArea::Episodic, $results[1]->area);
    }

    public function testExtractAllCanReturnPartialAreas(): void
    {
        // Une aire qui ne produit rien peut ne pas apparaître dans le retour
        // (sélectivité naturelle — design §38)
        $stub = new class implements MultiAreaExtractorInterface {
            public function supportedAreas(): array
            {
                return [BrainArea::Semantic, BrainArea::Episodic, BrainArea::Procedural];
            }

            public function extractAll(MemorySource $source): array
            {
                // Seule l'aire sémantique a produit quelque chose
                return [
                    ExtractionResult::empty(BrainArea::Semantic),
                ];
            }
        };

        $results = $stub->extractAll(new MemorySource('manual', []));

        $this->assertCount(1, $results);
    }
}
