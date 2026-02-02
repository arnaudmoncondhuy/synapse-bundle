<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Enum;

use ArnaudMoncondhuy\SynapseBundle\Enum\RiskLevel;
use PHPUnit\Framework\TestCase;

class RiskLevelTest extends TestCase
{
    public function testIsCritical(): void
    {
        $this->assertFalse(RiskLevel::NONE->isCritical());
        $this->assertFalse(RiskLevel::WARNING->isCritical());
        $this->assertTrue(RiskLevel::CRITICAL->isCritical());
    }

    public function testGetColor(): void
    {
        $this->assertEquals('green', RiskLevel::NONE->getColor());
        $this->assertEquals('orange', RiskLevel::WARNING->getColor());
        $this->assertEquals('red', RiskLevel::CRITICAL->getColor());
    }

    public function testGetEmoji(): void
    {
        $this->assertEquals('✅', RiskLevel::NONE->getEmoji());
        $this->assertEquals('⚠️', RiskLevel::WARNING->getEmoji());
        $this->assertEquals('🚨', RiskLevel::CRITICAL->getEmoji());
    }

    public function testGetLabel(): void
    {
        $this->assertEquals('Aucun risque', RiskLevel::NONE->getLabel());
        $this->assertEquals('Attention', RiskLevel::WARNING->getLabel());
        $this->assertEquals('Critique', RiskLevel::CRITICAL->getLabel());
    }
}
