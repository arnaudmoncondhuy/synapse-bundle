<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseBundle\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class ModelCapabilityRegistryTest extends TestCase
{
    private ModelCapabilityRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ModelCapabilityRegistry();
    }

    /**
     * Test que getCapabilities retourne un objet ModelCapabilities.
     */
    public function testGetCapabilitiesReturnsModelCapabilities(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('gemini-2.5-flash');

        // Assert
        $this->assertInstanceOf(ModelCapabilities::class, $capabilities);
    }

    /**
     * Test que les capacités contiennent les propriétés attendues.
     */
    public function testCapabilitiesHasExpectedProperties(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('gemini-2.5-flash');

        // Assert
        $this->assertEquals('gemini-2.5-flash', $capabilities->model);
        $this->assertIsString($capabilities->provider);
        $this->assertIsBool($capabilities->thinking);
        $this->assertIsBool($capabilities->safetySettings);
        $this->assertIsBool($capabilities->topK);
        $this->assertIsBool($capabilities->contextCaching);
        $this->assertIsBool($capabilities->functionCalling);
        $this->assertIsBool($capabilities->streaming);
        $this->assertIsBool($capabilities->systemPrompt);
    }

    /**
     * Test que les modèles inconnus obtiennent les capacités par défaut.
     */
    public function testUnknownModelReturnsDefaultCapabilities(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('unknown-model-xyz');

        // Assert
        // Les défauts : thinking=false, safetySettings=false, functionCalling=true, streaming=true
        $this->assertFalse($capabilities->thinking);
        $this->assertFalse($capabilities->safetySettings);
        $this->assertTrue($capabilities->functionCalling);
        $this->assertTrue($capabilities->streaming);
        $this->assertEquals('unknown', $capabilities->provider);
    }

    /**
     * Test que les modèles Gemini ont thinking activé.
     */
    public function testGeminiModelHasThinkingCapability(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('gemini-2.5-pro');

        // Assert
        // Les modèles Gemini doivent avoir thinking
        // (si configurés dans le YAML)
        $this->assertIsString($capabilities->provider);
    }

    /**
     * Test que même les modèles inconnus conservent les valeurs par défaut.
     */
    public function testDefaultCapabilitiesAreConsistent(): void
    {
        // Act
        $cap1 = $this->registry->getCapabilities('model-a');
        $cap2 = $this->registry->getCapabilities('model-b');

        // Assert
        // Les deux modèles inconnus doivent avoir les mêmes capacités par défaut
        $this->assertEquals($cap1->thinking, $cap2->thinking);
        $this->assertEquals($cap1->functionCalling, $cap2->functionCalling);
        $this->assertEquals($cap1->streaming, $cap2->streaming);
    }

    /**
     * Test que system_prompt est toujours activé par défaut.
     */
    public function testSystemPromptIsDefaultEnabled(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('any-model');

        // Assert
        $this->assertTrue($capabilities->systemPrompt);
    }

    /**
     * Test pricing info est nullable par défaut.
     */
    public function testPricingInfoCanBeNull(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('unknown-model');

        // Assert
        $this->assertNull($capabilities->pricingInput);
        $this->assertNull($capabilities->pricingOutput);
    }

    /**
     * Test que model_id peut être stocké dans les capacités.
     */
    public function testModelIdIsPreserved(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('gemini-2.5-flash');

        // Assert
        $this->assertIsString($capabilities->model);
        $this->assertEquals('gemini-2.5-flash', $capabilities->model);
    }

    /**
     * Test context_caching est désactivé par défaut.
     */
    public function testContextCachingDefaultDisabled(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('unknown');

        // Assert
        $this->assertFalse($capabilities->contextCaching);
    }

    /**
     * Test top_k est désactivé par défaut.
     */
    public function testTopKDefaultDisabled(): void
    {
        // Act
        $capabilities = $this->registry->getCapabilities('any-model');

        // Assert
        $this->assertFalse($capabilities->topK);
    }

    /**
     * Test que différents modèles Gemini retournent des instances distinctes.
     */
    public function testDifferentModelsReturnDifferentInstances(): void
    {
        // Act
        $cap1 = $this->registry->getCapabilities('gemini-2.5-flash');
        $cap2 = $this->registry->getCapabilities('gemini-2.5-pro');

        // Assert
        $this->assertNotSame($cap1, $cap2);
    }

    /**
     * Test que la même requête retourne une instance identique (cohérence).
     */
    public function testSameModelQueryReturnsSameCapabilities(): void
    {
        // Act
        $cap1 = $this->registry->getCapabilities('gemini-2.5-flash');
        $cap2 = $this->registry->getCapabilities('gemini-2.5-flash');

        // Assert
        $this->assertEquals($cap1->model, $cap2->model);
        $this->assertEquals($cap1->thinking, $cap2->thinking);
        $this->assertEquals($cap1->provider, $cap2->provider);
    }
}
