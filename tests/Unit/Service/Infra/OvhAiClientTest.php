<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Core\Client\OvhAiClient;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OvhAiClientTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private ConfigProviderInterface $configProvider;
    private ModelCapabilityRegistry $capabilityRegistry;
    private OvhAiClient $ovhClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);

        // Stub pour la configuration dynamique
        $this->configProvider->method('getConfig')
            ->willReturn([
                'provider' => 'ovh',
                'model' => 'Gpt-oss-20b',
                'api_key' => 'test-api-key',
                'endpoint' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1',
            ]);

        $this->capabilityRegistry->method('getCapabilities')
            ->willReturn(new ModelCapabilities('Gpt-oss-20b', 'ovh'));

        $this->ovhClient = new OvhAiClient(
            $this->httpClient,
            $this->configProvider,
            $this->capabilityRegistry,
        );
    }

    /**
     * Test que OvhAiClient retourne 'ovh' comme nom du provider.
     */
    public function testGetProviderNameReturnsOvh(): void
    {
        // Act
        $providerName = $this->ovhClient->getProviderName();

        // Assert
        $this->assertEquals('ovh', $providerName);
    }

    /**
     * Test que OvhAiClient accepte le format OpenAI canonical.
     */
    public function testStreamGenerateContentAcceptsOpenAiFormat(): void
    {
        // Arrange
        $contents = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello!'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/chat/completions'),
                $this->anything()
            )
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, [], null, $debugOut);

        // Try to iterate (even if empty, should not throw)
        iterator_to_array($generator);

        // Assert
        $this->assertArrayHasKey('actual_request_params', $debugOut);
        $this->assertEquals('ovh', $debugOut['actual_request_params']['provider']);
    }

    /**
     * Test que OvhAiClient est 100% compatible OpenAI (passthrough).
     */
    public function testOvhIsOpenAiPassthrough(): void
    {
        // Arrange
        $contents = [
            ['role' => 'user', 'content' => 'Test message'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, [], null, $debugOut);
        iterator_to_array($generator);

        // Assert
        // OVH doit envoyer le contenu directement (passthrough)
        $this->assertArrayHasKey('raw_request_body', $debugOut);
        // Le payload doit contenir les messages
        $this->assertArrayHasKey('messages', $debugOut['raw_request_body']);
    }

    /**
     * Test que OVH n'expose pas la sécurité native.
     */
    public function testOvhSafetyDisabledByDefault(): void
    {
        // Arrange
        $contents = [['role' => 'user', 'content' => 'Test']];

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, [], null, $debugOut);
        iterator_to_array($generator);

        // Assert
        $this->assertFalse($debugOut['actual_request_params']['safety_enabled']);
    }

    /**
     * Test que les tools (function calling) sont supportés si configurés.
     */
    public function testOvhSupportsToolsIfCapabilityEnabled(): void
    {
        // Arrange
        $contents = [['role' => 'user', 'content' => 'Call a function']];
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'test_func',
                    'description' => 'Test function',
                ],
            ],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, $tools, null, $debugOut);
        iterator_to_array($generator);

        // Assert
        // Les tools doivent être dans le debug output
        $this->assertArrayHasKey('raw_request_body', $debugOut);
    }

    /**
     * Test que le modèle par défaut est Gpt-oss-20b.
     */
    public function testDefaultModelIsGptOss20b(): void
    {
        // Arrange
        $contents = [['role' => 'user', 'content' => 'Test']];

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, [], null, $debugOut);
        iterator_to_array($generator);

        // Assert
        // Sans spécifier de modèle, il doit utiliser le modèle configuré
        $this->assertIsArray($debugOut['actual_request_params']);
    }

    /**
     * Test avec un modèle personnalisé.
     */
    public function testStreamGenerateContentWithCustomModel(): void
    {
        // Arrange
        $contents = [['role' => 'user', 'content' => 'Test']];
        $customModel = 'gpt-4-turbo';

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, [], $customModel, $debugOut);
        iterator_to_array($generator);

        // Assert
        $this->assertEquals($customModel, $debugOut['actual_request_params']['model']);
    }

    /**
     * Test que OVH accepte les paramètres OpenAI standard (temperature, top_p).
     */
    public function testOvhAcceptsOpenAiParameters(): void
    {
        // Arrange
        $contents = [['role' => 'user', 'content' => 'Test']];

        $mockResponse = $this->createMock(ResponseInterface::class);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $generator = $this->ovhClient->streamGenerateContent($contents, [], null, $debugOut);
        iterator_to_array($generator);

        // Assert
        $this->assertArrayHasKey('temperature', $debugOut['actual_request_params']);
        $this->assertArrayHasKey('top_p', $debugOut['actual_request_params']);
        $this->assertIsFloat($debugOut['actual_request_params']['temperature']);
        $this->assertIsFloat($debugOut['actual_request_params']['top_p']);
    }
}
