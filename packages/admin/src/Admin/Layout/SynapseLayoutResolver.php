<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Layout;

/**
 * Résout dynamiquement le layout admin à utiliser selon la configuration.
 */
class SynapseLayoutResolver
{
    public function __construct(
        private readonly string $layoutMode = 'standalone'
    ) {
    }

    /**
     * Retourne le chemin du layout admin à utiliser.
     * 
     * @return string Chemin du template (ex: '@Synapse/admin/layout.html.twig')
     */
    public function getAdminLayout(): string
    {
        return match ($this->layoutMode) {
            'module' => '@Synapse/admin/layout_module.html.twig',
            default => '@Synapse/admin/layout.html.twig',
        };
    }
}
