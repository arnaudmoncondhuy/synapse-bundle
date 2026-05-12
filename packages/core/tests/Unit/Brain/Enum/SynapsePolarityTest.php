<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Enum;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapsePolarity;
use PHPUnit\Framework\TestCase;

class SynapsePolarityTest extends TestCase
{
    public function testHasTwoValues(): void
    {
        $this->assertCount(2, SynapsePolarity::cases());
    }

    public function testExcitatoryValue(): void
    {
        $this->assertSame('excitatory', SynapsePolarity::Excitatory->value);
    }

    public function testInhibitoryValue(): void
    {
        $this->assertSame('inhibitory', SynapsePolarity::Inhibitory->value);
    }
}
