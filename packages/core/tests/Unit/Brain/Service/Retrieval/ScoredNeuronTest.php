<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ScoredNeuronTest extends TestCase
{
    public function testConstructWithDefaults(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'OK');
        $sn = new ScoredNeuron($neuron, 0.85, 0);

        $this->assertSame($neuron, $sn->neuron);
        $this->assertSame(0.85, $sn->score);
        $this->assertSame(0, $sn->depth);
        $this->assertNull($sn->reachedVia);
    }

    public function testConstructWithReachedVia(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'OK');
        $synapseId = Uuid::v7();

        $sn = new ScoredNeuron($neuron, 0.5, 2, $synapseId);

        $this->assertSame($synapseId, $sn->reachedVia);
        $this->assertSame(2, $sn->depth);
    }
}
