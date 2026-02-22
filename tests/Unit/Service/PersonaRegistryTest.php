<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Core\PersonaRegistry;
use PHPUnit\Framework\TestCase;

class PersonaRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/persona_registry_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*.json') as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * Test avec un fichier JSON inexistant.
     */
    public function testWithNonExistentConfigFile(): void
    {
        // Act
        $registry = new PersonaRegistry($this->tempDir . '/nonexistent.json');

        // Assert
        $this->assertEmpty($registry->getAll());
    }

    /**
     * Test getAll retourne tableau vide par défaut.
     */
    public function testGetAllReturnsEmptyArray(): void
    {
        // Arrange
        $configPath = $this->tempDir . '/empty.json';
        file_put_contents($configPath, json_encode([]));

        // Act
        $registry = new PersonaRegistry($configPath);

        // Assert
        $this->assertEmpty($registry->getAll());
    }

    /**
     * Test avec un persona valide.
     */
    public function testLoadSingleValidPersona(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'expert_php',
                'name' => 'Expert PHP',
                'system_prompt' => 'You are a PHP expert.',
                'description' => 'Specializes in PHP development',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);

        // Assert
        $all = $registry->getAll();
        $this->assertCount(1, $all);
        $this->assertArrayHasKey('expert_php', $all);
    }

    /**
     * Test get retourne un persona.
     */
    public function testGetReturnsPersona(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'creative',
                'name' => 'Creative Writer',
                'system_prompt' => 'You are creative.',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $persona = $registry->get('creative');

        // Assert
        $this->assertNotNull($persona);
        $this->assertEquals('creative', $persona['key']);
        $this->assertEquals('Creative Writer', $persona['name']);
    }

    /**
     * Test get retourne null pour persona inexistant.
     */
    public function testGetReturnsNullForNonExistent(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'existing',
                'system_prompt' => 'Prompt',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $result = $registry->get('nonexistent');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test getSystemPrompt retourne le prompt.
     */
    public function testGetSystemPromptReturnsPrompt(): void
    {
        // Arrange
        $prompt = 'You are an expert in mathematics.';
        $personas = [
            [
                'key' => 'mathematician',
                'name' => 'Mathematician',
                'system_prompt' => $prompt,
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $result = $registry->getSystemPrompt('mathematician');

        // Assert
        $this->assertEquals($prompt, $result);
    }

    /**
     * Test getSystemPrompt retourne null pour persona inexistant.
     */
    public function testGetSystemPromptReturnsNullForNonExistent(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'existing',
                'system_prompt' => 'Prompt',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $result = $registry->getSystemPrompt('nonexistent');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test plusieurs personas.
     */
    public function testLoadMultiplePersonas(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'expert_php',
                'name' => 'PHP Expert',
                'system_prompt' => 'You know PHP.',
            ],
            [
                'key' => 'expert_python',
                'name' => 'Python Expert',
                'system_prompt' => 'You know Python.',
            ],
            [
                'key' => 'translator',
                'name' => 'Translator',
                'system_prompt' => 'You translate texts.',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $all = $registry->getAll();

        // Assert
        $this->assertCount(3, $all);
        $this->assertArrayHasKey('expert_php', $all);
        $this->assertArrayHasKey('expert_python', $all);
        $this->assertArrayHasKey('translator', $all);
    }

    /**
     * Test personas sans clé sont ignorées.
     */
    public function testPersonasWithoutKeyAreIgnored(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'valid',
                'system_prompt' => 'Valid prompt',
            ],
            [
                'name' => 'Invalid without key',
                'system_prompt' => 'Invalid prompt',
            ],
            [
                'key' => 'another_valid',
                'system_prompt' => 'Another valid',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $all = $registry->getAll();

        // Assert
        $this->assertCount(2, $all);  // Sans celle sans key
        $this->assertArrayHasKey('valid', $all);
        $this->assertArrayHasKey('another_valid', $all);
    }

    /**
     * Test personas sans system_prompt sont ignorées.
     */
    public function testPersonasWithoutSystemPromptAreIgnored(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'valid',
                'system_prompt' => 'Valid prompt',
            ],
            [
                'key' => 'invalid',
                'name' => 'Invalid without prompt',
                // system_prompt manquant
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $all = $registry->getAll();

        // Assert
        $this->assertCount(1, $all);
        $this->assertArrayHasKey('valid', $all);
    }

    /**
     * Test JSON invalide est ignoré.
     */
    public function testInvalidJsonIsIgnored(): void
    {
        // Arrange
        $configPath = $this->tempDir . '/invalid.json';
        file_put_contents($configPath, 'not valid json {');

        // Act & Assert (doit ne pas lever d'exception)
        try {
            $registry = new PersonaRegistry($configPath);
            $all = $registry->getAll();
            $this->assertEmpty($all);
        } catch (\Exception $e) {
            $this->fail('Should not throw exception: ' . $e->getMessage());
        }
    }

    /**
     * Test getAll retourne copie exacte.
     */
    public function testGetAllReturnsExactCopy(): void
    {
        // Arrange
        $persona = [
            'key' => 'test',
            'name' => 'Test Persona',
            'system_prompt' => 'Test prompt',
            'description' => 'A test',
        ];
        $personas = [$persona];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $all = $registry->getAll();

        // Assert
        $this->assertArrayHasKey('test', $all);
        $this->assertEquals($persona, $all['test']);
    }

    /**
     * Test personas avec propriétés additionnelles.
     */
    public function testPersonasWithAdditionalProperties(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'full_featured',
                'name' => 'Full Featured',
                'system_prompt' => 'You are great.',
                'description' => 'A full persona',
                'category' => 'technical',
                'version' => '1.0',
                'tags' => ['expert', 'helpful'],
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $persona = $registry->get('full_featured');

        // Assert
        $this->assertNotNull($persona);
        $this->assertEquals('technical', $persona['category']);
        $this->assertEquals('1.0', $persona['version']);
        $this->assertContains('expert', $persona['tags']);
    }

    /**
     * Test getSystemPrompt extrait uniquement le prompt.
     */
    public function testGetSystemPromptExtractsOnlyPrompt(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'minimal',
                'name' => 'Minimal Persona',
                'system_prompt' => 'Only this matters for this method',
                'description' => 'Extra data',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $prompt = $registry->getSystemPrompt('minimal');

        // Assert
        $this->assertEquals('Only this matters for this method', $prompt);
        $this->assertIsString($prompt);
    }

    /**
     * Test avec clés spéciales.
     */
    public function testWithSpecialCharacterKeys(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'expert_python_3-11',
                'system_prompt' => 'Python 3.11 expert',
            ],
            [
                'key' => 'expert_c++',
                'system_prompt' => 'C++ expert',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);

        // Assert
        $this->assertNotNull($registry->get('expert_python_3-11'));
        $this->assertNotNull($registry->get('expert_c++'));
        $this->assertEquals('Python 3.11 expert', $registry->getSystemPrompt('expert_python_3-11'));
    }

    /**
     * Test que les modifications au array retourné n'affectent pas le registry.
     */
    public function testModifyingReturnedArrayDoesNotAffectRegistry(): void
    {
        // Arrange
        $personas = [
            [
                'key' => 'test',
                'system_prompt' => 'Test',
            ],
        ];
        $configPath = $this->tempDir . '/personas.json';
        file_put_contents($configPath, json_encode($personas));

        // Act
        $registry = new PersonaRegistry($configPath);
        $all = $registry->getAll();
        $all['test']['name'] = 'Modified';

        // Assert
        $original = $registry->get('test');
        $this->assertArrayNotHasKey('name', $original);
    }
}
