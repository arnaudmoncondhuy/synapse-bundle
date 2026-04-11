<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\Architect;

use ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectResponseSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectResponseSchema
 */
final class ArchitectResponseSchemaTest extends TestCase
{
    public function testCreateAgentSchemaHasRequiredStructure(): void
    {
        $schema = ArchitectResponseSchema::createAgent();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame('architect_create_agent', $schema['json_schema']['name']);
        // Chantier E phase 2 : strict=false pour permettre l'optionnel `allowed_tools`.
        $this->assertFalse($schema['json_schema']['strict']);

        $props = $schema['json_schema']['schema']['properties'];
        $this->assertArrayHasKey('key', $props);
        $this->assertArrayHasKey('name', $props);
        $this->assertArrayHasKey('emoji', $props);
        $this->assertArrayHasKey('description', $props);
        $this->assertArrayHasKey('system_prompt', $props);
        $this->assertArrayHasKey('allowed_tools', $props);
        $this->assertArrayHasKey('reasoning', $props);

        $required = $schema['json_schema']['schema']['required'];
        $this->assertContains('key', $required);
        $this->assertContains('system_prompt', $required);
        $this->assertNotContains('allowed_tools', $required);
    }

    public function testImprovePromptSchemaHasRequiredStructure(): void
    {
        $schema = ArchitectResponseSchema::improvePrompt();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame('architect_improve_prompt', $schema['json_schema']['name']);

        $props = $schema['json_schema']['schema']['properties'];
        $this->assertArrayHasKey('new_system_prompt', $props);
        $this->assertArrayHasKey('changes_summary', $props);
        $this->assertArrayHasKey('reasoning', $props);

        $required = $schema['json_schema']['schema']['required'];
        $this->assertContains('new_system_prompt', $required);
        $this->assertContains('changes_summary', $required);
    }

    public function testCreateWorkflowSchemaHasRequiredStructure(): void
    {
        $schema = ArchitectResponseSchema::createWorkflow();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame('architect_create_workflow', $schema['json_schema']['name']);

        $props = $schema['json_schema']['schema']['properties'];
        $this->assertArrayHasKey('key', $props);
        $this->assertArrayHasKey('name', $props);
        $this->assertArrayHasKey('steps', $props);
        $this->assertArrayHasKey('reasoning', $props);

        // Chantier K2 : les champs type-spécifiques sont regroupés dans un
        // sous-objet `config` au lieu d'être flat au niveau du step. Les 3
        // LLMs testés (Gemini 2.5 Pro/Flash, gpt-oss-120b) se plantaient
        // systématiquement sur le schéma flat (remplissaient `workflow_key`
        // avec n'importe quoi). Le wrapper `config` résout le problème.
        $stepProps = $props['steps']['items']['properties'];
        $this->assertArrayHasKey('name', $stepProps);
        $this->assertArrayHasKey('type', $stepProps);
        $this->assertArrayHasKey('config', $stepProps);
        $this->assertSame('object', $stepProps['config']['type']);

        $stepRequired = $props['steps']['items']['required'];
        $this->assertContains('name', $stepRequired);
        $this->assertContains('type', $stepRequired);
        $this->assertContains('config', $stepRequired);

        // Enum des types supportés
        $this->assertEqualsCanonicalizing(
            ['agent', 'conditional', 'parallel', 'loop', 'sub_workflow'],
            $stepProps['type']['enum'],
        );
    }

    public function testForActionReturnsCorrectSchema(): void
    {
        $this->assertSame(
            ArchitectResponseSchema::createAgent(),
            ArchitectResponseSchema::forAction('create_agent'),
        );
        $this->assertSame(
            ArchitectResponseSchema::improvePrompt(),
            ArchitectResponseSchema::forAction('improve_prompt'),
        );
        $this->assertSame(
            ArchitectResponseSchema::createWorkflow(),
            ArchitectResponseSchema::forAction('create_workflow'),
        );
    }

    public function testForActionThrowsOnUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inconnue/');

        ArchitectResponseSchema::forAction('invalid_action');
    }

    #[DataProvider('provideAllSchemas')]
    public function testAllSchemasAreValidJsonSchemaFormat(string $action): void
    {
        $schema = ArchitectResponseSchema::forAction($action);

        // Structure OpenAI response_format canonique
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('json_schema', $schema);
        $this->assertArrayHasKey('name', $schema['json_schema']);
        $this->assertArrayHasKey('schema', $schema['json_schema']);
        $this->assertArrayHasKey('strict', $schema['json_schema']);
        $this->assertArrayHasKey('type', $schema['json_schema']['schema']);
        $this->assertSame('object', $schema['json_schema']['schema']['type']);
        $this->assertArrayHasKey('properties', $schema['json_schema']['schema']);
        $this->assertArrayHasKey('required', $schema['json_schema']['schema']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAllSchemas(): iterable
    {
        yield 'create_agent' => ['create_agent'];
        yield 'improve_prompt' => ['improve_prompt'];
        yield 'create_workflow' => ['create_workflow'];
    }
}
