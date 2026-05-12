<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

class ProceduralNeuronTest extends TestCase
{
    public function testImplementsMemoryFragmentContract(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $this->assertInstanceOf(MemoryFragment::class, $neuron);
    }

    public function testGetAreaReturnsProcedural(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $this->assertSame(BrainArea::Procedural, $neuron->getArea());
    }

    public function testGetIdReturnsUuidV7(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $this->assertInstanceOf(UuidV7::class, $neuron->getId());
    }

    public function testSourceUuidPointsToSourceWhenProvided(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $this->assertSame(
            $source->getId()->toRfc4122(),
            $neuron->getSourceUuid()?->toRfc4122(),
        );
    }

    public function testSourceUuidIsNullableForManualProcedures(): void
    {
        // Une procédure peut être définie manuellement sans source brute
        $neuron = new ProceduralNeuron(null, 'manual-procedure', [], []);

        $this->assertNull($neuron->getSourceUuid());
    }

    public function testConstructorAssignsAllFields(): void
    {
        $source = new MemorySource('manual', []);
        $trigger = ['event_type' => 'webhook_received'];
        $steps = [
            ['type' => 'fetch_data', 'config' => ['source' => 'external']],
            ['type' => 'transform', 'config' => ['rules' => []]],
        ];
        $conditions = ['prerequisites' => ['has_credentials']];

        $neuron = new ProceduralNeuron(
            $source,
            'sync-external-data',
            $trigger,
            $steps,
            $conditions,
            0.85,
        );

        $this->assertSame('sync-external-data', $neuron->getName());
        $this->assertSame($trigger, $neuron->getTriggerPattern());
        $this->assertSame($steps, $neuron->getSteps());
        $this->assertSame($conditions, $neuron->getConditions());
        $this->assertSame(0.85, $neuron->getSuccessRate());
    }

    public function testInitialExecutionCountIsZero(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $this->assertSame(0, $neuron->getExecutionCount());
        $this->assertNull($neuron->getLastExecutedAt());
    }

    public function testDefaultSuccessRateIsHalf(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $this->assertSame(0.5, $neuron->getSuccessRate());
    }

    public function testRecordExecutionIncrementsCountAndUpdatesTimestamp(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], []);

        $before = new \DateTimeImmutable();
        $neuron->recordExecution(true);
        $after = new \DateTimeImmutable();

        $this->assertSame(1, $neuron->getExecutionCount());
        $this->assertNotNull($neuron->getLastExecutedAt());
        $this->assertGreaterThanOrEqual($before, $neuron->getLastExecutedAt());
        $this->assertLessThanOrEqual($after, $neuron->getLastExecutedAt());
    }

    public function testRecordExecutionSuccessIncrementsSuccessRate(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], [], successRate: 0.5);

        $neuron->recordExecution(true);

        // 0.5 * 0.9 + 1.0 * 0.1 = 0.55
        $this->assertSame(0.55, $neuron->getSuccessRate());
    }

    public function testRecordExecutionFailureDecrementsSuccessRate(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], [], successRate: 0.5);

        $neuron->recordExecution(false);

        // 0.5 * 0.9 + 0.0 * 0.1 = 0.45
        $this->assertSame(0.45, $neuron->getSuccessRate());
    }

    public function testSuccessRateIsClampedTo01(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new ProceduralNeuron($source, 'test', [], [], successRate: 1.0);

        // Même avec un succès, le successRate ne peut pas dépasser 1.0
        $neuron->recordExecution(true);

        $this->assertLessThanOrEqual(1.0, $neuron->getSuccessRate());
        $this->assertGreaterThanOrEqual(0.0, $neuron->getSuccessRate());
    }
}
