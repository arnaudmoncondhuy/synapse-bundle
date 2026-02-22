<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseExchangeCompletedEvent;
use PHPUnit\Framework\TestCase;

class SynapseExchangeCompletedEventTest extends TestCase
{
    /**
     * Test création minimale.
     */
    public function testMinimalCreation(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug-123',
            model: 'gemini-2.5-flash',
            provider: 'gemini'
        );

        // Assert
        $this->assertEquals('debug-123', $event->getDebugId());
        $this->assertEquals('gemini-2.5-flash', $event->getModel());
        $this->assertEquals('gemini', $event->getProvider());
        $this->assertEmpty($event->getUsage());
        $this->assertEmpty($event->getSafety());
        $this->assertFalse($event->isDebugMode());
        $this->assertEmpty($event->getRawData());
    }

    /**
     * Test creation with all parameters.
     */
    public function testCreationWithAllParameters(): void
    {
        // Arrange
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50];
        $safety = ['harassment' => 'LOW'];
        $rawData = ['model' => 'gemini-2.5-flash', 'finish_reason' => 'STOP'];

        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug-xyz',
            model: 'gemini-2.5-pro',
            provider: 'gemini',
            usage: $usage,
            safety: $safety,
            debugMode: true,
            rawData: $rawData
        );

        // Assert
        $this->assertEquals('debug-xyz', $event->getDebugId());
        $this->assertEquals('gemini-2.5-pro', $event->getModel());
        $this->assertEquals('gemini', $event->getProvider());
        $this->assertEquals($usage, $event->getUsage());
        $this->assertEquals($safety, $event->getSafety());
        $this->assertTrue($event->isDebugMode());
        $this->assertEquals($rawData, $event->getRawData());
    }

    /**
     * Test getDebugId.
     */
    public function testGetDebugId(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'abc-def-ghi',
            model: 'test',
            provider: 'test'
        );

        // Assert
        $this->assertEquals('abc-def-ghi', $event->getDebugId());
    }

    /**
     * Test getModel.
     */
    public function testGetModel(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'custom-model-v2',
            provider: 'custom'
        );

        // Assert
        $this->assertEquals('custom-model-v2', $event->getModel());
    }

    /**
     * Test getProvider.
     */
    public function testGetProvider(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'ovh'
        );

        // Assert
        $this->assertEquals('ovh', $event->getProvider());
    }

    /**
     * Test getUsage with tokens.
     */
    public function testGetUsageWithTokens(): void
    {
        // Arrange
        $usage = [
            'prompt_tokens' => 150,
            'completion_tokens' => 75,
            'thinking_tokens' => 50,
            'total_tokens' => 275,
        ];

        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'test',
            usage: $usage
        );

        // Assert
        $this->assertEquals($usage, $event->getUsage());
        $this->assertEquals(150, $event->getUsage()['prompt_tokens']);
    }

    /**
     * Test getSafety with ratings.
     */
    public function testGetSafetyWithRatings(): void
    {
        // Arrange
        $safety = [
            'harassment' => 'NEGLIGIBLE',
            'hate_speech' => 'LOW',
            'violent_content' => 'MEDIUM',
        ];

        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'test',
            safety: $safety
        );

        // Assert
        $this->assertEquals($safety, $event->getSafety());
        $this->assertEquals('NEGLIGIBLE', $event->getSafety()['harassment']);
    }

    /**
     * Test isDebugMode true.
     */
    public function testIsDebugModeTrue(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'test',
            debugMode: true
        );

        // Assert
        $this->assertTrue($event->isDebugMode());
    }

    /**
     * Test isDebugMode false.
     */
    public function testIsDebugModeFalse(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'test',
            debugMode: false
        );

        // Assert
        $this->assertFalse($event->isDebugMode());
    }

    /**
     * Test getRawData.
     */
    public function testGetRawData(): void
    {
        // Arrange
        $rawData = [
            'raw_api_response' => ['status' => 'success'],
            'request_params' => ['temperature' => 0.7],
        ];

        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'test',
            rawData: $rawData
        );

        // Assert
        $this->assertEquals($rawData, $event->getRawData());
    }

    /**
     * Test with complex usage data.
     */
    public function testWithComplexUsageData(): void
    {
        // Arrange
        $usage = [
            'prompt_tokens' => 1000000,
            'completion_tokens' => 500000,
            'thinking_tokens' => 250000,
            'total_tokens' => 1750000,
            'cost' => 2.575,
        ];

        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'large-debug',
            model: 'gemini-2.5-pro',
            provider: 'gemini',
            usage: $usage
        );

        // Assert
        $this->assertEquals(1000000, $event->getUsage()['prompt_tokens']);
        $this->assertEquals(2.575, $event->getUsage()['cost']);
    }

    /**
     * Test with empty arrays.
     */
    public function testWithEmptyArrays(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug',
            model: 'test',
            provider: 'test',
            usage: [],
            safety: [],
            rawData: []
        );

        // Assert
        $this->assertEmpty($event->getUsage());
        $this->assertEmpty($event->getSafety());
        $this->assertEmpty($event->getRawData());
    }

    /**
     * Test Gemini provider completion.
     */
    public function testGeminiProviderCompletion(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug-gemini-123',
            model: 'gemini-2.5-flash',
            provider: 'gemini',
            usage: [
                'prompt_tokens' => 200,
                'completion_tokens' => 100,
            ],
            safety: [
                'harassment' => 'NEGLIGIBLE',
            ],
            debugMode: true
        );

        // Assert
        $this->assertEquals('gemini', $event->getProvider());
        $this->assertEquals('gemini-2.5-flash', $event->getModel());
        $this->assertEquals(200, $event->getUsage()['prompt_tokens']);
    }

    /**
     * Test OVH provider completion.
     */
    public function testOvhProviderCompletion(): void
    {
        // Act
        $event = new SynapseExchangeCompletedEvent(
            debugId: 'debug-ovh-456',
            model: 'Gpt-oss-20b',
            provider: 'ovh',
            usage: [
                'prompt_tokens' => 150,
                'completion_tokens' => 75,
            ]
        );

        // Assert
        $this->assertEquals('ovh', $event->getProvider());
        $this->assertEquals('Gpt-oss-20b', $event->getModel());
    }
}
