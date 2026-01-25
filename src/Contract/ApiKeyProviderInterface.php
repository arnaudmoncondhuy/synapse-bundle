<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface pour la fourniture dynamique de la clé API Gemini.
 *
 * Implémentez cette interface pour fournir une clé différente selon l'utilisateur
 * connecté ou le contexte de la requête (Multi-tenancy).
 */
interface ApiKeyProviderInterface
{
    /**
     * Fournit la clé API Gemini à utiliser pour la requête actuelle.
     *
     * @return string|null La clé API ou null si non trouvée.
     */
    public function provideApiKey(): ?string;
}
