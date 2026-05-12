<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

class MemorySourceTest extends TestCase
{
    public function testConstructorGeneratesUuidV7(): void
    {
        $source = new MemorySource('manual', ['note' => 'test']);

        $this->assertInstanceOf(UuidV7::class, $source->getId());
    }

    public function testConstructorAssignsProviderAndPayload(): void
    {
        $payload = ['subject' => 'demo', 'body' => 'hello'];
        $source = new MemorySource('webhook_generic', $payload, 'ext-123');

        $this->assertSame('webhook_generic', $source->getProvider());
        $this->assertSame($payload, $source->getRawPayload());
        $this->assertSame('ext-123', $source->getExternalId());
    }

    public function testExternalIdIsNullableByDefault(): void
    {
        $source = new MemorySource('manual', []);

        $this->assertNull($source->getExternalId());
    }

    public function testOwnerIdIsNullableByDefault(): void
    {
        $source = new MemorySource('manual', []);

        $this->assertNull($source->getOwnerId());
    }

    public function testOwnerIdIsPreservedWhenProvided(): void
    {
        $owner = Uuid::v7();
        $source = new MemorySource('manual', [], null, $owner);

        $this->assertSame($owner, $source->getOwnerId());
    }

    public function testReceivedAtIsSetToNow(): void
    {
        $before = new \DateTimeImmutable();
        $source = new MemorySource('manual', []);
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $source->getReceivedAt());
        $this->assertLessThanOrEqual($after, $source->getReceivedAt());
    }

    public function testTwoSourcesHaveDistinctUuids(): void
    {
        $a = new MemorySource('manual', []);
        $b = new MemorySource('manual', []);

        $this->assertNotEquals($a->getId()->toRfc4122(), $b->getId()->toRfc4122());
    }
}
