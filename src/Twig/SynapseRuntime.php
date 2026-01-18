<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Twig;

use ArnaudMoncondhuy\SynapseBundle\Service\ChatService;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

class SynapseRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private ChatService $chatService,
        private Environment $twig
    ) {
    }

    public function renderWidget(array $options = []): string
    {
        // 1. Fetch History automatically
        $history = $this->chatService->getConversationHistory();

        // 2. Merge options (allows overriding history if needed, though rare)
        $context = array_merge([
            'history' => $history,
        ], $options);

        // 3. Render the component
        return $this->twig->render('@Synapse/chat/component.html.twig', $context);
    }
}
