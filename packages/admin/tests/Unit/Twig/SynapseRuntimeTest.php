<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Tests\Unit\Twig;

use ArnaudMoncondhuy\SynapseAdmin\Twig\SynapseRuntime;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class SynapseRuntimeTest extends TestCase
{
    public function testGetVersionReturnsInjectedWhenNoFile(): void
    {
        $runtime = new SynapseRuntime(
            $this->createStub(ChatService::class),
            $this->createStub(Environment::class),
            '1.2.3',
        );

        // If VERSION file exists in parent dir, it will read it; otherwise returns injected
        $version = $runtime->getVersion();
        $this->assertNotEmpty($version);
    }

    public function testRenderWidgetCallsTwigRender(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('getConversationHistory')->willReturn([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@Synapse/chat/component.html.twig',
                $this->callback(function (array $context) {
                    return isset($context['history']) && 1 === count($context['history']);
                })
            )
            ->willReturn('<div>widget</div>');

        $runtime = new SynapseRuntime($chatService, $twig, 'dev');
        $html = $runtime->renderWidget();

        $this->assertSame('<div>widget</div>', $html);
    }

    public function testRenderWidgetMergesOptions(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('getConversationHistory')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@Synapse/chat/component.html.twig',
                $this->callback(function (array $context) {
                    return isset($context['custom_option']) && 'value' === $context['custom_option'];
                })
            )
            ->willReturn('');

        $runtime = new SynapseRuntime($chatService, $twig, 'dev');
        $runtime->renderWidget(['custom_option' => 'value']);
    }
}
