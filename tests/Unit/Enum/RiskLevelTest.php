<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Enum;

use ArnaudMoncondhuy\SynapseBundle\Enum\RiskLevel;
use PHPUnit\Framework\TestCase;

class RiskLevelTest extends TestCase
{
    public function testHasRisk(): void
    {
        $this->assertFalse(RiskLevel::NONE->hasRisk());
        $this->assertTrue(RiskLevel::WARNING->hasRisk());
        $this->assertTrue(RiskLevel::CRITICAL->hasRisk());
    }

    public function testGetColor(): void
    {
        $this->assertEquals('gray', RiskLevel::NONE->getColor());
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
        $this->assertEquals('Avertissement', RiskLevel::WARNING->getLabel());
        $this->assertEquals('Critique', RiskLevel::CRITICAL->getLabel());
    }

    public function testPendingRisks(): void
    {
        $pending = RiskLevel::pendingRisks();

        $this->assertCount(2, $pending);
        $this->assertContains(RiskLevel::WARNING, $pending);
        $this->assertContains(RiskLevel::CRITICAL, $pending);
        $this->assertNotContains(RiskLevel::NONE, $pending);
    }

    public function testEnumValues(): void
    {
        $this->assertEquals('NONE', RiskLevel::NONE->value);
        $this->assertEquals('WARNING', RiskLevel::WARNING->value);
        $this->assertEquals('CRITICAL', RiskLevel::CRITICAL->value);
    }
}
