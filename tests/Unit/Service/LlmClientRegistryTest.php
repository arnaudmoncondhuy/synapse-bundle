<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface;
use PHPUnit\Framework\TestCase;

class LlmClientRegistryTest extends TestCase
{
    private ConfigProviderInterface $configProvider;
    private LlmClientRegistry $registry;

    protected function setUp(): void
    {
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
    }

    /**
     * Test avec un seul client enregistré.
     */
    public function testGetClientReturnsSingleRegisteredClient(): void
    {
        // Arrange
        $geminiClient = $this->createMock(LlmClientInterface::class);
        $geminiClient->method('getProviderName')->willReturn('gemini');

        $this->configProvider->method('getConfig')
            ->willReturn(['provider' => 'gemini']);

        $this->registry = new LlmClientRegistry(
            [$geminiClient],
            $this->configProvider,
            'gemini'
        );

        // Act
        $client = $this->registry->getClient();

        // Assert
        $this->assertSame($geminiClient, $client);
    }

    /**
     * Test avec plusieurs clients enregistrés.
     */
    public function testGetClientWithMultipleClientsReturnsConfigured(): void
    {
        // Arrange
        $geminiClient = $this->createMock(LlmClientInterface::class);
        $geminiClient->method('getProviderName')->willReturn('gemini');

        $ovhClient = $this->createMock(LlmClientInterface::class);
        $ovhClient->method('getProviderName')->willReturn('ovh');

        $this->configProvider->method('getConfig')
            ->willReturn(['provider' => 'ovh']);

        $this->registry = new LlmClientRegistry(
            [$geminiClient, $ovhClient],
            $this->configProvider,
            'gemini'
        );

        // Act
        $client = $this->registry->getClient();

        // Assert
        $this->assertSame($ovhClient, $client);
    }

    /**
     * Test fallback au provider par défaut si configuré n'existe pas.
     */
    public function testGetClientFallsBackToDefault(): void
    {
        // Arrange
        $geminiClient = $this->createMock(LlmClientInterface::class);
        $geminiClient->method('getProviderName')->willReturn('gemini');

        $this->configProvider->method('getConfig')
            ->willReturn(['provider' => 'unknown_provider']);

        $this->registry = new LlmClientRegistry(
            [$geminiClient],
            $this->configProvider,
            'gemini'
        );

        // Act
        $client = $this->registry->getClient();

        // Assert
        $this->assertSame($geminiClient, $client);
    }

    /**
     * Test exception si provider n'existe pas et pas de fallback.
     */
    public function testGetClientThrowsExceptionIfProviderNotFound(): void
    {
        // Arrange
        $this->configProvider->method('getConfig')
            ->willReturn(['provider' => 'nonexistent']);

        $this->registry = new LlmClientRegistry(
            [],
            $this->configProvider,
            'gemini'
        );

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider LLM "nonexistent" non disponible');

        $this->registry->getClient();
    }

    /**
     * Test getAvailableProviders retourne les noms des providers.
     */
    public function testGetAvailableProvidersReturnsAllProviderNames(): void
    {
        // Arrange
        $geminiClient = $this->createMock(LlmClientInterface::class);
        $geminiClient->method('getProviderName')->willReturn('gemini');

        $ovhClient = $this->createMock(LlmClientInterface::class);
        $ovhClient->method('getProviderName')->willReturn('ovh');

        $mistralClient = $this->createMock(LlmClientInterface::class);
        $mistralClient->method('getProviderName')->willReturn('mistral');

        $this->configProvider->method('getConfig')
            ->willReturn([]);

        $this->registry = new LlmClientRegistry(
            [$geminiClient, $ovhClient, $mistralClient],
            $this->configProvider
        );

        // Act
        $providers = $this->registry->getAvailableProviders();

        // Assert
        $this->assertCount(3, $providers);
        $this->assertContains('gemini', $providers);
        $this->assertContains('ovh', $providers);
        $this->assertContains('mistral', $providers);
    }

    /**
     * Test avec config vide (utilise le provider par défaut).
     */
    public function testGetClientWithEmptyConfigUsesDefault(): void
    {
        // Arrange
        $geminiClient = $this->createMock(LlmClientInterface::class);
        $geminiClient->method('getProviderName')->willReturn('gemini');

        $this->configProvider->method('getConfig')
            ->willReturn([]);  // Config vide

        $this->registry = new LlmClientRegistry(
            [$geminiClient],
            $this->configProvider,
            'gemini'
        );

        // Act
        $client = $this->registry->getClient();

        // Assert
        $this->assertSame($geminiClient, $client);
    }

    /**
     * Test exception contient liste des providers disponibles.
     */
    public function testExceptionMessageListsAvailableProviders(): void
    {
        // Arrange
        $abcClient = $this->createMock(LlmClientInterface::class);
        $abcClient->method('getProviderName')->willReturn('abc');

        $ovhClient = $this->createMock(LlmClientInterface::class);
        $ovhClient->method('getProviderName')->willReturn('ovh');

        $this->configProvider->method('getConfig')
            ->willReturn(['provider' => 'nonexistent']);

        $this->registry = new LlmClientRegistry(
            [$abcClient, $ovhClient],
            $this->configProvider,
            'gemini'
        );

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('abc');
        $this->expectExceptionMessage('ovh');

        $this->registry->getClient();
    }

    /**
     * Test clients peuvent être des instances différentes.
     */
    public function testRegistryStoresUniqueClientInstances(): void
    {
        // Arrange
        $geminiClient = $this->createMock(LlmClientInterface::class);
        $geminiClient->method('getProviderName')->willReturn('gemini');

        $ovhClient = $this->createMock(LlmClientInterface::class);
        $ovhClient->method('getProviderName')->willReturn('ovh');

        $this->configProvider->method('getConfig')
            ->willReturnOnConsecutiveCalls(
                ['provider' => 'gemini'],
                ['provider' => 'ovh']
            );

        $this->registry = new LlmClientRegistry(
            [$geminiClient, $ovhClient],
            $this->configProvider
        );

        // Act
        $client1 = $this->registry->getClient();
        $client2 = $this->registry->getClient();

        // Assert
        $this->assertSame($geminiClient, $client1);
        $this->assertSame($ovhClient, $client2);
        $this->assertNotSame($client1, $client2);
    }
}
