<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxPresetTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxPresetTool
 */
class CreateSandboxPresetToolTest extends TestCase
{
    public function testCreatesPresetSuccessfully(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            fn (SynapseModelPreset $p) => $p->isEphemeral() && 'test_preset' === $p->getKey() && !$p->isActive()
        ));
        $em->expects($this->once())->method('flush');

        $repo = $this->createStub(SynapseModelPresetRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $caps = $this->createStub(ModelCapabilityRegistry::class);
        $caps->method('isKnownModel')->willReturn(true);

        $tool = new CreateSandboxPresetTool($em, $repo, $caps, $this->makeAdmin());
        $result = $tool('test_preset', 'Test', 'google_vertex_ai', 'gemini-2.5-flash');

        $this->assertSame('success', $result['status']);
        $this->assertSame('test_preset', $result['presetKey']);
    }

    public function testRejectsDuplicateKey(): void
    {
        $repo = $this->createStub(SynapseModelPresetRepository::class);
        $repo->method('findByKey')->willReturn(new SynapseModelPreset());

        $tool = new CreateSandboxPresetTool(
            $this->createStub(EntityManagerInterface::class),
            $repo,
            $this->createStub(ModelCapabilityRegistry::class),
            $this->makeAdmin(),
        );
        $result = $tool('existing', 'Test', 'google_vertex_ai', 'gemini');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('already exists', $result['error']);
    }

    public function testRejectsUnknownModel(): void
    {
        $repo = $this->createStub(SynapseModelPresetRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $caps = $this->createStub(ModelCapabilityRegistry::class);
        $caps->method('isKnownModel')->willReturn(false);
        $caps->method('getModelsForProvider')->willReturn(['model-a', 'model-b']);

        $tool = new CreateSandboxPresetTool(
            $this->createStub(EntityManagerInterface::class),
            $repo,
            $caps,
            $this->makeAdmin(),
        );
        $result = $tool('test', 'Test', 'google_vertex_ai', 'unknown-model');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not known', $result['error']);
        $this->assertStringContainsString('model-a', $result['error']);
    }

    public function testRejectsInvalidKeyFormat(): void
    {
        $tool = new CreateSandboxPresetTool(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseModelPresetRepository::class),
            $this->createStub(ModelCapabilityRegistry::class),
            $this->makeAdmin(),
        );
        $result = $tool('INVALID KEY!', 'Test', 'provider', 'model');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid key', $result['error']);
    }

    private function makeAdmin(): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(true);

        return $checker;
    }
}
