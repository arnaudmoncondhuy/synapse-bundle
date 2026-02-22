<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Core\PersonaRegistry;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderTest extends TestCase
{
    private ContextProviderInterface $contextProvider;
    private PersonaRegistry $personaRegistry;
    private ConfigProviderInterface $configProvider;
    private PromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        $this->contextProvider = $this->createMock(ContextProviderInterface::class);
        $this->personaRegistry = $this->createMock(PersonaRegistry::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);

        $this->promptBuilder = new PromptBuilder(
            $this->contextProvider,
            $this->personaRegistry,
            $this->configProvider
        );
    }

    /**
     * Test que buildSystemMessage retourne le format OpenAI canonical.
     */
    public function testBuildSystemMessageReturnsOpenAiFormat(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')
            ->willReturn('Application base prompt');
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemMessage();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('role', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertEquals('system', $result['role']);
        $this->assertIsString($result['content']);
    }

    /**
     * Test que le contenu du message système contient les composants attendus.
     */
    public function testBuildSystemMessageContentIncludesTechnicalAndBasePrompt(): void
    {
        // Arrange
        $basePrompt = 'BASE_APP_PROMPT';
        $this->contextProvider->method('getSystemPrompt')
            ->willReturn($basePrompt);
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemMessage();

        // Assert
        $this->assertStringContainsString('BASE_APP_PROMPT', $result['content']);
        $this->assertStringContainsString('CADRE TECHNIQUE DE RÉPONSE', $result['content']);
        $this->assertStringContainsString('Format Markdown propre', $result['content']);
    }

    /**
     * Test l'injection de personnalité dans le message système.
     */
    public function testBuildSystemMessageInjectsPersonaWhenProvided(): void
    {
        // Arrange
        $basePrompt = 'BASE';
        $personaPrompt = 'Tu es un expert en Symfony avec 10 ans d\'expérience.';

        $this->contextProvider->method('getSystemPrompt')
            ->willReturn($basePrompt);
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->expects($this->once())
            ->method('getSystemPrompt')
            ->with('symfony_expert')
            ->willReturn($personaPrompt);

        // Act
        $result = $this->promptBuilder->buildSystemMessage('symfony_expert');

        // Assert
        $this->assertStringContainsString($personaPrompt, $result['content']);
        $this->assertStringContainsString('PERSONALITY INSTRUCTIONS', $result['content']);
        $this->assertStringContainsString('IMPORTANT : La personnalité suivante s\'applique UNIQUEMENT', $result['content']);
    }

    /**
     * Test que les personnalités inconnues sont ignorées.
     */
    public function testBuildSystemMessageIgnoresUnknownPersona(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')
            ->willReturn('BASE');
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->expects($this->once())
            ->method('getSystemPrompt')
            ->with('unknown_persona')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemMessage('unknown_persona');

        // Assert
        $this->assertStringNotContainsString('PERSONALITY INSTRUCTIONS', $result['content']);
    }

    /**
     * Test l'interpolation de variables depuis le contexte.
     */
    public function testBuildSystemMessageInterpolatesContextVariables(): void
    {
        // Arrange
        $systemPrompt = 'User email: {EMAIL}, Role: {ROLE}, Date: {DATE}';
        $context = [
            'date' => '2026-02-22',
            'user' => [
                'email' => 'user@example.com',
                'role' => 'admin',
            ],
        ];

        $this->configProvider->method('getConfig')
            ->willReturn(['system_prompt' => $systemPrompt]);
        $this->contextProvider->method('getInitialContext')
            ->willReturn($context);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemMessage();

        // Assert
        $this->assertStringContainsString('user@example.com', $result['content']);
        $this->assertStringContainsString('admin', $result['content']);
        $this->assertStringContainsString('2026-02-22', $result['content']);
        $this->assertStringNotContainsString('{EMAIL}', $result['content']);
        $this->assertStringNotContainsString('{ROLE}', $result['content']);
    }

    /**
     * Test que buildSystemInstruction retourne une chaîne de caractères.
     */
    public function testBuildSystemInstructionReturnsString(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')
            ->willReturn('Base prompt');
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemInstruction();

        // Assert
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test que les deux méthodes produisent du contenu cohérent.
     */
    public function testBuildSystemMessageAndInstructionAreConsistent(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')
            ->willReturn('Application base prompt');
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $message = $this->promptBuilder->buildSystemMessage();
        $instruction = $this->promptBuilder->buildSystemInstruction();

        // Assert
        // Le contenu du message système doit être égal à l'instruction
        $this->assertEquals($instruction, $message['content']);
    }

    /**
     * Test avec persona : cohérence entre les deux méthodes.
     */
    public function testBuildSystemMessageAndInstructionWithPersonaAreConsistent(): void
    {
        // Arrange
        $personaKey = 'expert_code';
        $personaPrompt = 'You are a code expert.';

        $this->contextProvider->method('getSystemPrompt')
            ->willReturn('Base prompt');
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->method('getSystemPrompt')
            ->with($personaKey)
            ->willReturn($personaPrompt);

        // Act
        $message = $this->promptBuilder->buildSystemMessage($personaKey);
        $instruction = $this->promptBuilder->buildSystemInstruction($personaKey);

        // Assert
        $this->assertEquals($instruction, $message['content']);
    }

    /**
     * Test avec prompt système depuis la configuration (database).
     */
    public function testBuildSystemMessageWithConfigSystemPrompt(): void
    {
        // Arrange
        $configPrompt = 'Welcome, {EMAIL}!';
        $context = [
            'user' => ['email' => 'test@example.com'],
        ];

        $this->configProvider->method('getConfig')
            ->willReturn(['system_prompt' => $configPrompt]);
        $this->contextProvider->method('getInitialContext')
            ->willReturn($context);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemMessage();

        // Assert
        $this->assertStringContainsString('Welcome, test@example.com!', $result['content']);
    }

    /**
     * Test que le prompt technique est toujours présent et au début.
     */
    public function testTechnicalPromptIsAlwaysIncluded(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')
            ->willReturn('Custom prompt');
        $this->configProvider->method('getConfig')
            ->willReturn([]);
        $this->personaRegistry->method('getSystemPrompt')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemMessage();

        // Assert
        $this->assertStringContainsString('CADRE TECHNIQUE DE RÉPONSE', $result['content']);
        // Vérifie que le prompt technique vient avant le prompt personnalisé
        $technicalPos = strpos($result['content'], 'CADRE TECHNIQUE');
        $customPos = strpos($result['content'], 'Custom prompt');
        $this->assertLessThan($customPos, $technicalPos);
    }
}
