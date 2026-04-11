<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Chat\ChatIntentRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Chat\ChatIntentRouter
 */
final class ChatIntentRouterTest extends TestCase
{
    private ChatIntentRouter $router;

    protected function setUp(): void
    {
        $this->router = new ChatIntentRouter();
    }

    #[DataProvider('agentIntentMessages')]
    public function testDetectsAgentCreationIntent(string $message): void
    {
        $result = $this->router->route($message);
        $this->assertNotNull($result, sprintf('Expected agent intent for message: %s', $message));
        $this->assertSame('create_agent', $result['action']);
        $this->assertSame($message, $result['description']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function agentIntentMessages(): iterable
    {
        yield 'fr-imperative' => ['crée-moi un agent qui résume un texte en 3 bullets'];
        yield 'fr-imperative-alt' => ['Crée un agent capable d\'analyser un PDF'];
        yield 'fr-narrative' => ['Je voudrais un agent qui traduit du français vers l\'anglais'];
        yield 'fr-generate' => ['Génère-moi un agent de classification de tickets support'];
        yield 'fr-fabricate' => ['Fabrique un nouvel agent pour relire mes mails'];
        yield 'en-imperative' => ['Create me an agent that summarizes articles'];
        yield 'en-build' => ['Build an agent to detect sentiment in tweets'];
        yield 'en-narrative' => ['I want an agent that can extract entities from text'];
    }

    #[DataProvider('workflowIntentMessages')]
    public function testDetectsWorkflowCreationIntent(string $message): void
    {
        $result = $this->router->route($message);
        $this->assertNotNull($result, sprintf('Expected workflow intent for message: %s', $message));
        $this->assertSame('create_workflow', $result['action']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function workflowIntentMessages(): iterable
    {
        yield 'fr-workflow' => ['Crée-moi un workflow qui analyse un PDF puis le traduit'];
        yield 'fr-pipeline' => ['Fais-moi un pipeline d\'analyse de code'];
        yield 'fr-chain' => ['Construis une chaîne d\'agents pour traiter des tickets'];
        yield 'en-workflow' => ['Create a workflow that extracts and translates'];
        yield 'en-pipeline' => ['Build me a pipeline for invoice processing'];
        yield 'en-want' => ['I need a new workflow for email triage'];
    }

    #[DataProvider('noIntentMessages')]
    public function testReturnsNullForNonArchitectMessages(string $message): void
    {
        $this->assertNull(
            $this->router->route($message),
            sprintf('Expected no intent for message: %s', $message)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noIntentMessages(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'question' => ['Quelle est la capitale de la France ?'];
        yield 'command-generic' => ['Résume ce texte pour moi'];
        yield 'greeting' => ['Bonjour, comment ça va ?'];
        // Usage du mot agent sans intention de création
        yield 'agent-mention' => ['L\'agent que tu m\'as donné hier ne fonctionne plus'];
        yield 'run-agent' => ['Lance l\'agent redacteur sur ce texte'];
    }

    public function testWorkflowTakesPrecedenceOverAgent(): void
    {
        // Message qui mentionne les deux : « workflow d'agents » → priorité workflow
        $result = $this->router->route('Crée-moi un workflow d\'agents qui traite un PDF');
        $this->assertNotNull($result);
        $this->assertSame('create_workflow', $result['action']);
    }

    public function testShouldRouteToArchitectBoolean(): void
    {
        $this->assertTrue($this->router->shouldRouteToArchitect('Crée-moi un agent qui résume'));
        $this->assertFalse($this->router->shouldRouteToArchitect('Bonjour'));
    }
}
