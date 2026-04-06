<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Tests\Unit\Twig;

use ArnaudMoncondhuy\SynapseAdmin\Twig\SynapseTwigExtension;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\ToneRegistry;
use PHPUnit\Framework\TestCase;

class SynapseTwigExtensionTest extends TestCase
{
    private function createExtension(
        ?EncryptionServiceInterface $encryption = null,
        ?PermissionCheckerInterface $permission = null,
        ?ConfigProviderInterface $config = null,
        ?ModelCapabilityRegistry $capabilities = null,
    ): SynapseTwigExtension {
        return new SynapseTwigExtension(
            $this->createStub(ToneRegistry::class),
            $this->createStub(AgentRegistry::class),
            $encryption,
            $permission,
            $config,
            $capabilities,
        );
    }

    // ── parseMarkdown ───────────────────────────────────────────────────

    public function testParseMarkdownBold(): void
    {
        $ext = $this->createExtension();
        $result = $ext->parseMarkdown('Hello **world**');

        $this->assertStringContainsString('<strong>world</strong>', $result);
    }

    public function testParseMarkdownItalic(): void
    {
        $ext = $this->createExtension();
        $result = $ext->parseMarkdown('Hello *world*');

        $this->assertStringContainsString('<em>world</em>', $result);
    }

    public function testParseMarkdownLink(): void
    {
        $ext = $this->createExtension();
        $result = $ext->parseMarkdown('[Click](https://example.com)');

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('synapse-btn-action', $result);
    }

    public function testParseMarkdownInlineCode(): void
    {
        $ext = $this->createExtension();
        $result = $ext->parseMarkdown('Use `foo()` here');

        $this->assertStringContainsString('<code>foo()</code>', $result);
    }

    public function testParseMarkdownEmpty(): void
    {
        $ext = $this->createExtension();
        $this->assertSame('', $ext->parseMarkdown(null));
        $this->assertSame('', $ext->parseMarkdown(''));
    }

    public function testParseMarkdownEscapesHtml(): void
    {
        $ext = $this->createExtension();
        $result = $ext->parseMarkdown('<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testParseMarkdownDecryptsIfNeeded(): void
    {
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('isEncrypted')->willReturn(true);
        $encryption->method('decrypt')->willReturn('Decrypted text');

        $ext = $this->createExtension(encryption: $encryption);
        $result = $ext->parseMarkdown('encrypted-content');

        $this->assertStringContainsString('Decrypted text', $result);
    }

    // ── safeHtml ────────────────────────────────────────────────────────

    public function testSafeHtmlAllowedTags(): void
    {
        $ext = $this->createExtension();

        $this->assertStringContainsString('<strong>', $ext->safeHtml('<strong>bold</strong>'));
        $this->assertStringContainsString('<em>', $ext->safeHtml('<em>italic</em>'));
        $this->assertStringContainsString('<code>', $ext->safeHtml('<code>code</code>'));
    }

    public function testSafeHtmlStripsUnsafeTags(): void
    {
        $ext = $this->createExtension();

        $this->assertStringNotContainsString('<script>', $ext->safeHtml('<script>alert(1)</script>'));
        $this->assertStringNotContainsString('<div>', $ext->safeHtml('<div>content</div>'));
    }

    public function testSafeHtmlReturnsEmptyForNull(): void
    {
        $ext = $this->createExtension();

        $this->assertSame('', $ext->safeHtml(null));
        $this->assertSame('', $ext->safeHtml(''));
    }

    // ── canDebug ────────────────────────────────────────────────────────

    public function testCanDebugReturnsFalseWithoutPermissionChecker(): void
    {
        $ext = $this->createExtension();
        $this->assertFalse($ext->canDebug());
    }

    public function testCanDebugDelegatesToPermissionChecker(): void
    {
        $permission = $this->createStub(PermissionCheckerInterface::class);
        $permission->method('canAccessAdmin')->willReturn(true);

        $ext = $this->createExtension(permission: $permission);
        $this->assertTrue($ext->canDebug());
    }

    // ── activeModelSupports ─────────────────────────────────────────────

    public function testActiveModelSupportsReturnsFalseWithoutDeps(): void
    {
        $ext = $this->createExtension();
        $this->assertFalse($ext->activeModelSupports('vision'));
    }

    public function testActiveModelSupportsReturnsTrue(): void
    {
        $config = $this->createStub(ConfigProviderInterface::class);
        $runtimeConfig = SynapseRuntimeConfig::fromArray([
            'model' => 'gemini-pro-vision',
            'provider' => 'google',
        ]);
        $config->method('getConfig')->willReturn($runtimeConfig);

        $capabilities = $this->createStub(ModelCapabilityRegistry::class);
        $capabilities->method('supports')->with('gemini-pro-vision', 'vision')->willReturn(true);

        $ext = $this->createExtension(config: $config, capabilities: $capabilities);
        $this->assertTrue($ext->activeModelSupports('vision'));
    }

    public function testActiveModelSupportsReturnsFalseWhenDisabled(): void
    {
        $config = $this->createStub(ConfigProviderInterface::class);
        $runtimeConfig = SynapseRuntimeConfig::fromArray([
            'model' => 'gemini-pro',
            'provider' => 'google',
            'disabled_capabilities' => ['streaming'],
        ]);
        $config->method('getConfig')->willReturn($runtimeConfig);

        $capabilities = $this->createStub(ModelCapabilityRegistry::class);
        $capabilities->method('supports')->willReturn(true);

        $ext = $this->createExtension(config: $config, capabilities: $capabilities);
        $this->assertFalse($ext->activeModelSupports('streaming'));
    }

    // ── activeModelAcceptedMimes ─────────────────────────────────────────

    public function testActiveModelAcceptedMimesReturnsEmptyWithoutDeps(): void
    {
        $ext = $this->createExtension();
        $this->assertSame([], $ext->activeModelAcceptedMimes());
    }

    public function testActiveModelAcceptedMimesReturnsMimes(): void
    {
        $config = $this->createStub(ConfigProviderInterface::class);
        $runtimeConfig = SynapseRuntimeConfig::fromArray([
            'model' => 'gemini-pro-vision',
            'provider' => 'google',
        ]);
        $config->method('getConfig')->willReturn($runtimeConfig);

        $caps = new ModelCapabilities(model: 'gemini-pro-vision', provider: 'google', supportsVision: true);
        $capabilities = $this->createStub(ModelCapabilityRegistry::class);
        $capabilities->method('getCapabilities')->willReturn($caps);

        $ext = $this->createExtension(config: $config, capabilities: $capabilities);
        $mimes = $ext->activeModelAcceptedMimes();

        $this->assertContains('image/jpeg', $mimes);
    }

    // ── getFilters / getFunctions ────────────────────────────────────────

    public function testGetFiltersReturnsExpected(): void
    {
        $ext = $this->createExtension();
        $filters = $ext->getFilters();

        $names = array_map(fn ($f) => $f->getName(), $filters);
        $this->assertContains('synapse_markdown', $names);
        $this->assertContains('safe_html', $names);
    }

    public function testGetFunctionsReturnsExpected(): void
    {
        $ext = $this->createExtension();
        $functions = $ext->getFunctions();

        $names = array_map(fn ($f) => $f->getName(), $functions);
        $this->assertContains('synapse_can_debug', $names);
        $this->assertContains('synapse_active_model_supports', $names);
        $this->assertContains('synapse_active_model_accepted_mimes', $names);
    }
}
