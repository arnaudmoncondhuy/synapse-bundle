<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Enum;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SynapseRelationTypeTest extends TestCase
{
    public function testHasNineValues(): void
    {
        $this->assertCount(9, SynapseRelationType::cases());
    }

    #[DataProvider('expectedValuesProvider')]
    public function testValueExists(string $expected): void
    {
        $this->assertNotNull(SynapseRelationType::tryFrom($expected));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expectedValuesProvider(): iterable
    {
        yield 'causal' => ['causal'];
        yield 'corroborates' => ['corroborates'];
        yield 'contradicts' => ['contradicts'];
        yield 'composes' => ['composes'];
        yield 'instantiates' => ['instantiates'];
        yield 'temporal' => ['temporal'];
        yield 'spatial' => ['spatial'];
        yield 'emotional' => ['emotional'];
        yield 'generic' => ['generic'];
    }
}
