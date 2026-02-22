<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapsePrePromptEvent;
use PHPUnit\Framework\TestCase;

class SynapsePrePromptEventTest extends TestCase
{
    /**
     * Test création avec message minimal.
     */
    public function testEventCreationWithMessage(): void
    {
        // Arrange
        $message = 'Hello, how are you?';

        // Act
        $event = new SynapsePrePromptEvent($message, []);

        // Assert
        $this->assertEquals($message, $event->getMessage());
        $this->assertEmpty($event->getOptions());
        $this->assertEmpty($event->getPrompt());
        $this->assertEmpty($event->getConfig());
    }

    /**
     * Test création avec options.
     */
    public function testEventCreationWithOptions(): void
    {
        // Arrange
        $message = 'Test';
        $options = ['debug' => true, 'persona' => 'expert'];

        // Act
        $event = new SynapsePrePromptEvent($message, $options);

        // Assert
        $this->assertEquals($options, $event->getOptions());
        $this->assertTrue($event->getOptions()['debug']);
        $this->assertEquals('expert', $event->getOptions()['persona']);
    }

    /**
     * Test création avec prompt initial.
     */
    public function testEventCreationWithInitialPrompt(): void
    {
        // Arrange
        $message = 'Test';
        $prompt = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Test'],
        ];

        // Act
        $event = new SynapsePrePromptEvent($message, [], $prompt);

        // Assert
        $this->assertEquals($prompt, $event->getPrompt());
        $this->assertCount(2, $event->getPrompt());
    }

    /**
     * Test création avec config initial.
     */
    public function testEventCreationWithInitialConfig(): void
    {
        // Arrange
        $message = 'Test';
        $config = [
            'streaming_enabled' => true,
            'debug_mode' => false,
        ];

        // Act
        $event = new SynapsePrePromptEvent($message, [], [], $config);

        // Assert
        $this->assertEquals($config, $event->getConfig());
        $this->assertTrue($event->getConfig()['streaming_enabled']);
    }

    /**
     * Test setPrompt et getPrompt.
     */
    public function testSetAndGetPrompt(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);
        $newPrompt = [
            ['role' => 'system', 'content' => 'New system'],
            ['role' => 'user', 'content' => 'New user message'],
        ];

        // Act
        $event->setPrompt($newPrompt);

        // Assert
        $this->assertEquals($newPrompt, $event->getPrompt());
    }

    /**
     * Test setPrompt retourne $this.
     */
    public function testSetPromptReturnsSelf(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);

        // Act
        $result = $event->setPrompt([]);

        // Assert
        $this->assertSame($event, $result);
    }

    /**
     * Test setConfig et getConfig.
     */
    public function testSetAndGetConfig(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);
        $newConfig = [
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.7,
        ];

        // Act
        $event->setConfig($newConfig);

        // Assert
        $this->assertEquals($newConfig, $event->getConfig());
    }

    /**
     * Test setConfig retourne $this.
     */
    public function testSetConfigReturnsSelf(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);

        // Act
        $result = $event->setConfig([]);

        // Assert
        $this->assertSame($event, $result);
    }

    /**
     * Test getter chainning.
     */
    public function testChainingSetting(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Initial', ['debug' => true]);

        // Act
        $event->setPrompt([['role' => 'user', 'content' => 'Test']])
            ->setConfig(['model' => 'test']);

        // Assert
        $this->assertNotEmpty($event->getPrompt());
        $this->assertNotEmpty($event->getConfig());
        $this->assertTrue($event->getOptions()['debug']);
    }

    /**
     * Test message getter.
     */
    public function testGetMessageReturnsOriginal(): void
    {
        // Arrange
        $message = 'Important message';

        // Act
        $event = new SynapsePrePromptEvent($message, []);

        // Assert
        $this->assertEquals($message, $event->getMessage());
    }

    /**
     * Test options getter retourne exact options.
     */
    public function testGetOptionsReturnsExactOptions(): void
    {
        // Arrange
        $options = [
            'preset' => 'code_expert',
            'persona' => 'python_expert',
            'max_tokens' => 2000,
        ];

        // Act
        $event = new SynapsePrePromptEvent('Message', $options);

        // Assert
        $this->assertEquals($options, $event->getOptions());
        $this->assertEquals('code_expert', $event->getOptions()['preset']);
    }

    /**
     * Test avec prompt et config complexes.
     */
    public function testWithComplexPromptAndConfig(): void
    {
        // Arrange
        $prompt = [
            ['role' => 'system', 'content' => 'System instruction'],
            ['role' => 'user', 'content' => 'User question'],
            ['role' => 'assistant', 'content' => 'Previous response'],
        ];

        $config = [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'streaming_enabled' => true,
            'debug_mode' => true,
            'temperature' => 0.7,
            'max_tokens' => 2048,
        ];

        // Act
        $event = new SynapsePrePromptEvent('New question', [], $prompt, $config);

        // Assert
        $this->assertCount(3, $event->getPrompt());
        $this->assertCount(6, $event->getConfig());
        $this->assertEquals('gemini', $event->getConfig()['provider']);
    }

    /**
     * Test updating prompt doesn't affect original.
     */
    public function testUpdatingPromptDoesNotAffectOriginal(): void
    {
        // Arrange
        $originalPrompt = [
            ['role' => 'user', 'content' => 'Original'],
        ];
        $event = new SynapsePrePromptEvent('Message', [], $originalPrompt);

        // Act
        $newPrompt = [
            ['role' => 'user', 'content' => 'Updated'],
        ];
        $event->setPrompt($newPrompt);

        // Assert
        $this->assertEquals($newPrompt, $event->getPrompt());
        $this->assertNotEquals($originalPrompt[0]['content'], $event->getPrompt()[0]['content']);
    }

    /**
     * Test message avec caractères spéciaux.
     */
    public function testMessageWithSpecialCharacters(): void
    {
        // Arrange
        $message = 'Bonjour! Comment ça va? 🚀 Test "quotes" & symbols';

        // Act
        $event = new SynapsePrePromptEvent($message, []);

        // Assert
        $this->assertEquals($message, $event->getMessage());
    }

    /**
     * Test empty options array.
     */
    public function testEmptyOptionsArray(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);

        // Act
        $options = $event->getOptions();

        // Assert
        $this->assertIsArray($options);
        $this->assertEmpty($options);
    }

    /**
     * Test empty prompt array initially.
     */
    public function testEmptyPromptInitially(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);

        // Act
        $prompt = $event->getPrompt();

        // Assert
        $this->assertIsArray($prompt);
        $this->assertEmpty($prompt);
    }

    /**
     * Test empty config initially.
     */
    public function testEmptyConfigInitially(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);

        // Act
        $config = $event->getConfig();

        // Assert
        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    /**
     * Test updating config multiple times.
     */
    public function testUpdatingConfigMultipleTimes(): void
    {
        // Arrange
        $event = new SynapsePrePromptEvent('Message', []);

        // Act
        $event->setConfig(['version' => 1]);
        $event->setConfig(['version' => 2, 'model' => 'test']);
        $event->setConfig(['version' => 3]);

        // Assert
        $config = $event->getConfig();
        $this->assertEquals(3, $config['version']);
        $this->assertArrayNotHasKey('model', $config);
    }
}
