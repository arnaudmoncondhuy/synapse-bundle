<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Core\Client\GeminiClient;
use ArnaudMoncondhuy\SynapseBundle\Core\Client\GoogleAuthService;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GeminiClientTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private GoogleAuthService $googleAuthService;
    private ConfigProviderInterface $configProvider;
    private ModelCapabilityRegistry $capabilityRegistry;
    private GeminiClient $geminiClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->googleAuthService = $this->createMock(GoogleAuthService::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);

        // Stub pour la configuration dynamique
        $this->configProvider->method('getConfig')
            ->willReturn([
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash',
                'vertex_project_id' => 'test-project',
                'vertex_region' => 'europe-west1',
            ]);

        $this->capabilityRegistry->method('getCapabilities')
            ->willReturn(new ModelCapabilities('gemini-2.5-flash', 'gemini'));

        $this->geminiClient = new GeminiClient(
            $this->httpClient,
            $this->googleAuthService,
            $this->configProvider,
            $this->capabilityRegistry,
        );
    }

    /**
     * Test que generateContent accepte le format OpenAI canonical.
     * Les contenus contiennent déjà le message système en première position.
     */
    public function testGenerateContentAcceptsOpenAiFormatContents(): void
    {
        // Arrange
        $contents = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'I am doing well, thank you!']],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 15,
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $result = $this->geminiClient->generateContent($contents, [], null, null, $debugOut);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['blocked'] === false || $result['blocked'] === null);
    }

    /**
     * Test que generateContent retourne un chunk normalisé.
     */
    public function testGenerateContentReturnsNormalizedChunk(): void
    {
        // Arrange
        $contents = [
            ['role' => 'user', 'content' => 'Bonjour'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Bonjour! Comment puis-je vous aider?']],
                    ],
                ],
            ],
        ]);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        // Act
        $result = $this->geminiClient->generateContent($contents);

        // Assert
        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('blocked', $result);
        $this->assertArrayHasKey('thinking', $result);
    }

    /**
     * Test que la méthode fournit le nom du provider.
     */
    public function testGetProviderNameReturnsGemini(): void
    {
        // Act
        $providerName = $this->geminiClient->getProviderName();

        // Assert
        $this->assertEquals('gemini', $providerName);
    }

    /**
     * Test avec des tools (function calling).
     */
    public function testGenerateContentWithTools(): void
    {
        // Arrange
        $contents = [
            ['role' => 'user', 'content' => 'Appelle la fonction test'],
        ];

        $tools = [
            [
                'function_declarations' => [
                    [
                        'name' => 'test_function',
                        'description' => 'A test function',
                    ],
                ],
            ],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'test_function',
                                    'args' => ['param' => 'value'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        // Act
        $result = $this->geminiClient->generateContent($contents, $tools);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('function_calls', $result);
    }

    /**
     * Test le debug output avec les paramètres réels envoyés.
     */
    public function testGenerateContentPopulatesDebugOutput(): void
    {
        // Arrange
        $contents = [
            ['role' => 'user', 'content' => 'Test'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Response']],
                    ],
                ],
            ],
        ]);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $this->geminiClient->generateContent($contents, [], null, null, $debugOut);

        // Assert
        $this->assertArrayHasKey('actual_request_params', $debugOut);
        $this->assertArrayHasKey('raw_request_body', $debugOut);
        $this->assertArrayHasKey('raw_api_response', $debugOut);
        $this->assertArrayHasKey('model', $debugOut['actual_request_params']);
        $this->assertEquals('gemini', $debugOut['actual_request_params']['provider']);
    }

    /**
     * Test avec un modèle personnalisé.
     */
    public function testGenerateContentWithCustomModel(): void
    {
        // Arrange
        $contents = [
            ['role' => 'user', 'content' => 'Test with custom model'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Response']],
                    ],
                ],
            ],
        ]);

        $this->httpClient->method('request')
            ->with(
                'POST',
                $this->stringContains('gemini-2.5-pro'),
                $this->anything()
            )
            ->willReturn($mockResponse);

        $this->capabilityRegistry->method('getCapabilities')
            ->willReturnMap([
                ['gemini-2.5-flash', new ModelCapabilities('gemini-2.5-flash', 'gemini')],
                ['gemini-2.5-pro', new ModelCapabilities('gemini-2.5-pro', 'gemini')],
            ]);

        // Act
        $result = $this->geminiClient->generateContent(
            $contents,
            [],
            'gemini-2.5-pro'
        );

        // Assert
        $this->assertIsArray($result);
    }

    /**
     * Test que les exceptions sont gérées correctement.
     */
    public function testGenerateContentHandlesHttpException(): void
    {
        // Arrange
        $contents = [
            ['role' => 'user', 'content' => 'Test'],
        ];

        $this->httpClient->method('request')
            ->willThrowException(new \Exception('Network error'));

        // Act
        $result = $this->geminiClient->generateContent($contents);

        // Assert
        // Une exception devrait être loggée, et un chunk vide retourné
        $this->assertIsArray($result);
    }

    /**
     * Test que les messages système dans contents sont traités.
     */
    public function testGenerateContentProcessesSystemMessageFromContents(): void
    {
        // Arrange
        $systemPrompt = 'You are an expert assistant.';
        $contents = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Hello!']],
                    ],
                ],
            ],
        ]);

        $this->httpClient->method('request')
            ->willReturn($mockResponse);

        $debugOut = [];

        // Act
        $this->geminiClient->generateContent($contents, [], null, null, $debugOut);

        // Assert
        // Le système doit être extrait des contents et traité
        $this->assertArrayHasKey('actual_request_params', $debugOut);
        $this->assertArrayHasKey('raw_request_body', $debugOut);
    }
}
