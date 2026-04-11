<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxAgentTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxAgentTool
 */
class CreateSandboxAgentToolTest extends TestCase
{
    public function testCreatesAgentSuccessfully(): void
    {
        $preset = new SynapseModelPreset();
        $preset->setName('Test Preset');
        $preset->setModel('gemini-2.5-flash');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            fn (SynapseAgent $a) => $a->isEphemeral()
                && 'test_agent' === $a->getKey()
                && 'Test Agent' === $a->getName()
                && $a->isActive()
                && !$a->isBuiltin()
        ));
        $em->expects($this->once())->method('flush');

        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn(null);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findByKey')->willReturn($preset);

        $tool = new CreateSandboxAgentTool($em, $agentRepo, $presetRepo, $this->makeAdmin());
        $result = $tool('test_agent', 'Test Agent', 'You are a test agent.', presetKey: 'my_preset');

        $this->assertSame('success', $result['status']);
        $this->assertSame('test_agent', $result['agentKey']);
        $this->assertSame('Test Preset', $result['presetUsed']);
    }

    public function testFallsBackToActivePreset(): void
    {
        $activePreset = new SynapseModelPreset();
        $activePreset->setName('Active');
        $activePreset->setModel('default-model');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn(null);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findActive')->willReturn($activePreset);

        $tool = new CreateSandboxAgentTool($em, $agentRepo, $presetRepo, $this->makeAdmin());
        $result = $tool('test', 'Test', 'prompt');

        $this->assertSame('success', $result['status']);
        $this->assertSame('Active', $result['presetUsed']);
    }

    public function testRejectsDuplicateKey(): void
    {
        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn(new SynapseAgent());

        $tool = new CreateSandboxAgentTool(
            $this->createStub(EntityManagerInterface::class),
            $agentRepo,
            $this->createStub(SynapseModelPresetRepository::class),
            $this->makeAdmin(),
        );
        $result = $tool('existing', 'Test', 'prompt');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('already exists', $result['error']);
    }

    public function testRejectsInvalidKeyFormat(): void
    {
        $tool = new CreateSandboxAgentTool(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(SynapseModelPresetRepository::class),
            $this->makeAdmin(),
        );
        $result = $tool('INVALID', 'Test', 'prompt');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid key', $result['error']);
    }

    public function testDeniesAccessWithoutAdmin(): void
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(false);

        $tool = new CreateSandboxAgentTool(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(SynapseModelPresetRepository::class),
            $checker,
        );
        $result = $tool('test', 'Test', 'prompt');

        $this->assertSame('error', $result['status']);
    }

    private function makeAdmin(): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn(true);

        return $checker;
    }
}
