<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Contract;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MemoryFragmentTest extends TestCase
{
    public function testStubImplementationRespectsContract(): void
    {
        $id = Uuid::v7();
        $sourceUuid = Uuid::v7();

        $fragment = new class($id, $sourceUuid) implements MemoryFragment {
            public function __construct(
                private readonly Uuid $id,
                private readonly ?Uuid $sourceUuid,
            ) {
            }

            public function getId(): Uuid
            {
                return $this->id;
            }

            public function getArea(): BrainArea
            {
                return BrainArea::Semantic;
            }

            public function getSourceUuid(): ?Uuid
            {
                return $this->sourceUuid;
            }
        };

        $this->assertSame($id, $fragment->getId());
        $this->assertSame(BrainArea::Semantic, $fragment->getArea());
        $this->assertSame($sourceUuid, $fragment->getSourceUuid());
    }

    public function testSourceUuidCanBeNull(): void
    {
        $fragment = new class implements MemoryFragment {
            public function getId(): Uuid
            {
                return Uuid::v7();
            }

            public function getArea(): BrainArea
            {
                return BrainArea::Procedural;
            }

            public function getSourceUuid(): ?Uuid
            {
                return null;
            }
        };

        $this->assertNull($fragment->getSourceUuid());
    }
}
