<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseMcp\Tool\ListPresetsTool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseMcp\Tool\ListPresetsTool
 */
class ListPresetsToolTest extends TestCase
{
    public function testListsPresetsExcludingSandbox(): void
    {
        $preset = $this->makePreset('fast', 'Fast', 'google_vertex_ai', 'gemini-2.5-flash');

        $repo = $this->createMock(SynapseModelPresetRepository::class);
        $repo->expects($this->once())->method('findAllPresets')->willReturn([$preset]);

        $tool = new ListPresetsTool($repo, $this->makePermissionChecker(true));
        $result = $tool();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('fast', $result['presets'][0]['key']);
        $this->assertSame('gemini-2.5-flash', $result['presets'][0]['model']);
    }

    public function testListsPresetsIncludingSandbox(): void
    {
        $regular = $this->makePreset('fast', 'Fast', 'google_vertex_ai', 'gemini-2.5-flash');
        $sandbox = $this->makePreset('sb_test', 'Sandbox', 'ovh', 'Qwen3-32B', true);

        $repo = $this->createMock(SynapseModelPresetRepository::class);
        $repo->expects($this->once())->method('findAll')->willReturn([$regular, $sandbox]);

        $tool = new ListPresetsTool($repo, $this->makePermissionChecker(true));
        $result = $tool(includeSandbox: true);

        $this->assertSame(2, $result['count']);
        $this->assertTrue($result['presets'][1]['isSandbox']);
    }

    public function testDeniesAccessWithoutAdmin(): void
    {
        $repo = $this->createStub(SynapseModelPresetRepository::class);
        $tool = new ListPresetsTool($repo, $this->makePermissionChecker(false));
        $result = $tool();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Access denied', $result['error']);
    }

    private function makePreset(string $key, string $name, string $provider, string $model, bool $sandbox = false): SynapseModelPreset
    {
        $preset = new SynapseModelPreset();
        $preset->setKey($key);
        $preset->setName($name);
        $preset->setProviderName($provider);
        $preset->setModel($model);
        $preset->setIsSandbox($sandbox);

        return $preset;
    }

    private function makePermissionChecker(bool $isAdmin): PermissionCheckerInterface
    {
        $checker = $this->createStub(PermissionCheckerInterface::class);
        $checker->method('canAccessAdmin')->willReturn($isAdmin);

        return $checker;
    }
}
