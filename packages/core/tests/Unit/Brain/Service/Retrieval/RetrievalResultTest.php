<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalResult;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class RetrievalResultTest extends TestCase
{
    public function testConstructWithEmpty(): void
    {
        $r = new RetrievalResult([]);

        $this->assertTrue($r->isEmpty());
        $this->assertSame(0, $r->count());
        $this->assertSame([], $r->debug);
    }

    public function testConstructWithNeurons(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'OK');
        $scored = new ScoredNeuron($neuron, 0.75, 1);

        $r = new RetrievalResult([$scored], debug: ['seeds' => 1]);

        $this->assertFalse($r->isEmpty());
        $this->assertSame(1, $r->count());
        $this->assertSame(['seeds' => 1], $r->debug);
    }

    public function testEmptyFactory(): void
    {
        $r = RetrievalResult::empty();

        $this->assertTrue($r->isEmpty());
        $this->assertSame([], $r->neurons);
    }
}
