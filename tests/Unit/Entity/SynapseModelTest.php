<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseModel;
use PHPUnit\Framework\TestCase;

class SynapseModelTest extends TestCase
{
    /**
     * Test création avec valeurs par défaut.
     */
    public function testDefaultValues(): void
    {
        // Act
        $model = new SynapseModel();

        // Assert
        $this->assertNull($model->getId());
        $this->assertEquals('', $model->getProviderName());
        $this->assertEquals('', $model->getModelId());
        $this->assertEquals('', $model->getLabel());
        $this->assertTrue($model->isEnabled());
        $this->assertNull($model->getPricingInput());
        $this->assertNull($model->getPricingOutput());
        $this->assertEquals(0, $model->getSortOrder());
    }

    /**
     * Test setProviderName et getProviderName.
     */
    public function testSetAndGetProviderName(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setProviderName('gemini');

        // Assert
        $this->assertEquals('gemini', $model->getProviderName());
    }

    /**
     * Test setModelId et getModelId.
     */
    public function testSetAndGetModelId(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setModelId('gemini-2.5-flash');

        // Assert
        $this->assertEquals('gemini-2.5-flash', $model->getModelId());
    }

    /**
     * Test setLabel et getLabel.
     */
    public function testSetAndGetLabel(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setLabel('Gemini 2.5 Flash');

        // Assert
        $this->assertEquals('Gemini 2.5 Flash', $model->getLabel());
    }

    /**
     * Test setEnabled et isEnabled.
     */
    public function testSetAndIsEnabled(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setEnabled(false);

        // Assert
        $this->assertFalse($model->isEnabled());
    }

    /**
     * Test enable et disable.
     */
    public function testEnableAndDisable(): void
    {
        // Arrange
        $model = new SynapseModel();
        $model->setEnabled(false);

        // Act
        $model->enable();

        // Assert
        $this->assertTrue($model->isEnabled());

        // Act
        $model->disable();

        // Assert
        $this->assertFalse($model->isEnabled());
    }

    /**
     * Test setPricingInput et getPricingInput.
     */
    public function testSetAndGetPricingInput(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setPricingInput(0.075);

        // Assert
        $this->assertEquals(0.075, $model->getPricingInput());
    }

    /**
     * Test setPricingOutput et getPricingOutput.
     */
    public function testSetAndGetPricingOutput(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setPricingOutput(0.3);

        // Assert
        $this->assertEquals(0.3, $model->getPricingOutput());
    }

    /**
     * Test setSortOrder et getSortOrder.
     */
    public function testSetAndGetSortOrder(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setSortOrder(5);

        // Assert
        $this->assertEquals(5, $model->getSortOrder());
    }

    /**
     * Test setters retournent $this pour chainning.
     */
    public function testSettersReturnThis(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $result = $model->setProviderName('gemini')
            ->setModelId('gemini-2.5-flash')
            ->setLabel('Gemini 2.5 Flash');

        // Assert
        $this->assertSame($model, $result);
    }

    /**
     * Test propriétés fluides chainning.
     */
    public function testFluentPropertyChaining(): void
    {
        // Act
        $model = (new SynapseModel())
            ->setProviderName('gemini')
            ->setModelId('gemini-2.5-flash')
            ->setLabel('Gemini 2.5 Flash')
            ->setPricingInput(0.075)
            ->setPricingOutput(0.3)
            ->setSortOrder(1)
            ->setEnabled(true);

        // Assert
        $this->assertEquals('gemini', $model->getProviderName());
        $this->assertEquals('gemini-2.5-flash', $model->getModelId());
        $this->assertEquals('Gemini 2.5 Flash', $model->getLabel());
        $this->assertEquals(0.075, $model->getPricingInput());
        $this->assertEquals(0.3, $model->getPricingOutput());
        $this->assertEquals(1, $model->getSortOrder());
        $this->assertTrue($model->isEnabled());
    }

    /**
     * Test pricing values avec décimales.
     */
    public function testPricingWithDecimalValues(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setPricingInput(0.00375)
            ->setPricingOutput(0.015);

        // Assert
        $this->assertEquals(0.00375, $model->getPricingInput());
        $this->assertEquals(0.015, $model->getPricingOutput());
    }

    /**
     * Test sort order avec valeurs négatives.
     */
    public function testSortOrderWithNegativeValues(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act
        $model->setSortOrder(-10);

        // Assert
        $this->assertEquals(-10, $model->getSortOrder());
    }

    /**
     * Test que disabled models peuvent être re-enabled.
     */
    public function testDisabledModelsCanBeReEnabled(): void
    {
        // Arrange
        $model = new SynapseModel();
        $model->disable();

        // Act
        $model->enable();

        // Assert
        $this->assertTrue($model->isEnabled());
    }

    /**
     * Test avec modèle Gemini typique.
     */
    public function testTypicalGeminiModel(): void
    {
        // Act
        $model = (new SynapseModel())
            ->setProviderName('gemini')
            ->setModelId('gemini-2.5-pro')
            ->setLabel('Gemini 2.5 Pro (Thinking)')
            ->setPricingInput(1.25)
            ->setPricingOutput(10.00)
            ->setSortOrder(0)
            ->setEnabled(true);

        // Assert
        $this->assertEquals('gemini', $model->getProviderName());
        $this->assertEquals('gemini-2.5-pro', $model->getModelId());
        $this->assertTrue($model->isEnabled());
        $this->assertEquals(1.25, $model->getPricingInput());
        $this->assertEquals(10.00, $model->getPricingOutput());
    }

    /**
     * Test avec modèle OVH typique.
     */
    public function testTypicalOvhModel(): void
    {
        // Act
        $model = (new SynapseModel())
            ->setProviderName('ovh')
            ->setModelId('Gpt-oss-20b')
            ->setLabel('GPT OSS 20B')
            ->setPricingInput(0.05)
            ->setPricingOutput(0.15)
            ->setSortOrder(10)
            ->setEnabled(true);

        // Assert
        $this->assertEquals('ovh', $model->getProviderName());
        $this->assertEquals('Gpt-oss-20b', $model->getModelId());
        $this->assertEquals(0.05, $model->getPricingInput());
    }

    /**
     * Test pricing nullable.
     */
    public function testPricingCanBeNull(): void
    {
        // Arrange
        $model = new SynapseModel();
        $model->setPricingInput(1.0)
            ->setPricingOutput(2.0);

        // Act
        $model->setPricingInput(null)
            ->setPricingOutput(null);

        // Assert
        $this->assertNull($model->getPricingInput());
        $this->assertNull($model->getPricingOutput());
    }

    /**
     * Test modèle avec label long.
     */
    public function testModelWithLongLabel(): void
    {
        // Arrange
        $model = new SynapseModel();
        $longLabel = 'Gemini 2.5 Flash with Extended Thinking and Custom Safety Settings';

        // Act
        $model->setLabel($longLabel);

        // Assert
        $this->assertEquals($longLabel, $model->getLabel());
    }

    /**
     * Test multiple enable/disable cycles.
     */
    public function testMultipleEnableDisableCycles(): void
    {
        // Arrange
        $model = new SynapseModel();

        // Act & Assert
        $model->enable();
        $this->assertTrue($model->isEnabled());

        $model->disable();
        $this->assertFalse($model->isEnabled());

        $model->enable();
        $this->assertTrue($model->isEnabled());

        $model->setEnabled(false);
        $this->assertFalse($model->isEnabled());
    }
}
