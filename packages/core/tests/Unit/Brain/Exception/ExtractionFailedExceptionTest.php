<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Exception;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;

class ExtractionFailedExceptionTest extends TestCase
{
    public function testCarriesSourceAndArea(): void
    {
        $source = new MemorySource('manual', []);
        $exception = new ExtractionFailedException($source, BrainArea::Semantic, 'LLM down');

        $this->assertSame($source, $exception->source);
        $this->assertSame(BrainArea::Semantic, $exception->area);
    }

    public function testMessageContainsContext(): void
    {
        $source = new MemorySource('manual', []);
        $exception = new ExtractionFailedException($source, BrainArea::Episodic, 'invalid JSON');

        $message = $exception->getMessage();

        $this->assertStringContainsString('episodic', $message);
        $this->assertStringContainsString('invalid JSON', $message);
        $this->assertStringContainsString($source->getId()->toRfc4122(), $message);
    }

    public function testPreservesPrevious(): void
    {
        $source = new MemorySource('manual', []);
        $previous = new \RuntimeException('underlying');
        $exception = new ExtractionFailedException(
            $source,
            BrainArea::Semantic,
            'wrap',
            $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testIsRuntimeException(): void
    {
        $source = new MemorySource('manual', []);
        $exception = new ExtractionFailedException($source, BrainArea::Semantic, 'reason');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
