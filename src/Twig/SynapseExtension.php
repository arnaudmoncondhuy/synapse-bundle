<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Extension Twig définissant les fonctions personnalisées du bundle.
 *
 * Expose les fonctions utilisables directement dans les templates `.html.twig`.
 */
class SynapseExtension extends AbstractExtension
{
    public function __construct(
        private \ArnaudMoncondhuy\SynapseBundle\Service\PersonaRegistry $personaRegistry,
        private \ArnaudMoncondhuy\SynapseBundle\Service\SynapseLayoutResolver $layoutResolver,
        private \ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository $configRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            // Affiche le widget de chat complet (HTML + JS auto-connecté)
            new TwigFunction('synapse_chat_widget', [SynapseRuntime::class, 'renderWidget'], ['is_safe' => ['html']]),

            // Retourne la liste des personas disponibles (pour créer un sélecteur par exemple)
            new TwigFunction('synapse_get_personas', [$this->personaRegistry, 'getAll']),
            
            // Résout dynamiquement le layout admin à utiliser (standalone ou module)
            new TwigFunction('synapse_admin_layout', [$this->layoutResolver, 'getAdminLayout']),

            // Récupère la configuration (Entité)
            new TwigFunction('synapse_config', [$this->configRepository, 'getConfig']),
        ];
    }
}
