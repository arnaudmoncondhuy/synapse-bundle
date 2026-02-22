<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Core\PersonaRegistry;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderTest extends TestCase
{
    private $contextProvider;
    private $personaRegistry;
    private $promptBuilder;

    protected function setUp(): void
    {
        $this->contextProvider = $this->createMock(ContextProviderInterface::class);
        $this->personaRegistry = $this->createMock(PersonaRegistry::class);

        $this->promptBuilder = new PromptBuilder(
            $this->contextProvider,
            $this->personaRegistry
        );
    }

    public function testBuildSystemInstructionHasTechnicalAndBasePrompt(): void
    {
        // Arrange
        $this->contextProvider->expects($this->once())
            ->method('getSystemPrompt')
            ->willReturn('BASE_APP_PROMPT');

        // Act
        $result = $this->promptBuilder->buildSystemInstruction();

        // Assert
        $this->assertStringContainsString('BASE_APP_PROMPT', $result);
        $this->assertStringContainsString('CADRE TECHNIQUE DE RÉPONSE', $result, 'Le prompt technique doit être présent');
        $this->assertStringNotContainsString('<thinking>', $result, 'Le prompt ne doit pas contenir les balises <thinking> (thinking natif)');
    }

    public function testBuildSystemInstructionInjectsPersonaIfProvided(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')->willReturn('BASE');

        $this->personaRegistry->expects($this->once())
            ->method('getSystemPrompt')
            ->with('expert_code')
            ->willReturn('Tu es un expert Symfony.');

        // Act
        $result = $this->promptBuilder->buildSystemInstruction('expert_code');

        // Assert
        $this->assertStringContainsString('BASE', $result);
        $this->assertStringContainsString('Tu es un expert Symfony.', $result);
        $this->assertStringContainsString('PERSONALITY INSTRUCTIONS', $result);
        $this->assertStringContainsString('IMPORTANT : La personnalité suivante s\'applique UNIQUEMENT', $result);
    }

    public function testBuildSystemInstructionIgnoresUnknownPersona(): void
    {
        // Arrange
        $this->contextProvider->method('getSystemPrompt')->willReturn('BASE');

        $this->personaRegistry->expects($this->once())
            ->method('getSystemPrompt')
            ->with('unknown_key')
            ->willReturn(null);

        // Act
        $result = $this->promptBuilder->buildSystemInstruction('unknown_key');

        // Assert
        $this->assertStringContainsString('BASE', $result);
        $this->assertStringNotContainsString('PERSONALITY INSTRUCTIONS', $result);
    }
}
