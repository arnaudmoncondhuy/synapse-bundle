<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\CodeExecutor\ExecutionResult;
use ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool
 */
final class CodeExecuteToolTest extends TestCase
{
    public function testMetadataIsExposedForLlm(): void
    {
        $tool = new CodeExecuteTool(new NullCodeExecutor());

        $this->assertSame('code_execute', $tool->getName());
        $this->assertNotEmpty($tool->getLabel());
        $this->assertNotEmpty($tool->getDescription());

        $schema = $tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('code', $schema['properties']);
        $this->assertArrayHasKey('language', $schema['properties']);
        $this->assertContains('code', $schema['required']);
    }

    public function testExecuteRejectsEmptyCode(): void
    {
        $tool = new CodeExecuteTool(new NullCodeExecutor());

        $result = $tool->execute(['code' => '']);
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('InvalidInput', $result['error_type']);
    }

    public function testExecuteRejectsMissingCode(): void
    {
        $tool = new CodeExecuteTool(new NullCodeExecutor());

        $result = $tool->execute([]);
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('InvalidInput', $result['error_type']);
    }

    public function testExecuteDelegatesToExecutorAndSerializesResult(): void
    {
        // Spy executor qui enregistre ses appels et retourne un résultat prévisible.
        $spy = new class() implements CodeExecutorInterface {
            public array $calls = [];

            public function execute(string $code, string $language = 'python', array $inputs = [], array $options = []): ExecutionResult
            {
                $this->calls[] = ['code' => $code, 'language' => $language];

                return new ExecutionResult(
                    success: true,
                    stdout: "42\n",
                    returnValue: 42,
                    durationMs: 15,
                );
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedLanguages(): array
            {
                return ['python'];
            }
        };

        $tool = new CodeExecuteTool($spy);
        $result = $tool->execute(['code' => 'result = 6 * 7', 'language' => 'python']);

        $this->assertCount(1, $spy->calls);
        $this->assertSame('result = 6 * 7', $spy->calls[0]['code']);
        $this->assertSame('python', $spy->calls[0]['language']);

        $this->assertTrue($result['success']);
        $this->assertSame("42\n", $result['stdout']);
        $this->assertSame(42, $result['return_value']);
    }

    public function testExecuteWithNullExecutorPropagatesUnavailable(): void
    {
        $tool = new CodeExecuteTool(new NullCodeExecutor());
        $result = $tool->execute(['code' => 'print(1)']);

        $this->assertFalse($result['success']);
        $this->assertSame('BackendUnavailable', $result['error_type']);
    }
}
