<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Twig;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Runtime Twig gérant la logique d'affichage des composants Synapse.
 *
 * Cette classe est chargée paresseusement (Lazy Loading) par Twig uniquement si
 * les fonctions sont appelées dans le template.
 */
class SynapseRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private ChatService $chatService,
        private Environment $twig,
    ) {
    }

    /**
     * Rend le widget de chat.
     *
     * Pré-charge automatiquement l'historique de la conversation depuis le ChatService
     * pour l'afficher à l'initialisation du composant.
     *
     * @param array $options options d'affichage passées depuis Twig
     *
     * @return string le HTML généré
     */
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
