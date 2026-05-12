<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Enum;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseEdgeType;
use PHPUnit\Framework\TestCase;

class SynapseEdgeTypeTest extends TestCase
{
    public function testHasTwoValues(): void
    {
        $this->assertCount(2, SynapseEdgeType::cases());
    }

    public function testAssociationValue(): void
    {
        $this->assertSame('association', SynapseEdgeType::Association->value);
    }

    public function testTransductionValue(): void
    {
        $this->assertSame('transduction', SynapseEdgeType::Transduction->value);
    }
}
