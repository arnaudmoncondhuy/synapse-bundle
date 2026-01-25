<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface pour la fourniture de contexte au modèle IA.
 *
 * Implémentez cette interface pour injecter dynamiquement des "System Prompts"
 * et des données contextuelles (date, utilisateur connecté, environnement)
 * au début de chaque conversation Gemini.
 */
interface ContextProviderInterface
{
    /**
     * Retourne le prompt système principal (identité, règles, instructions de sécurité).
     *
     * C'est ici que l'on définit "qui" est l'IA et comment elle doit se comporter globalement.
     *
     * @return string le texte du prompt système
     */
    public function getSystemPrompt(): string;

    /**
     * Retourne le contexte initial à injecter dans la conversation.
     *
     * Utile pour fournir des données d'environnement immédiates (ex: "Nous sommes le 25/01/2026").
     *
     * @return array<string, mixed> tableau clé-valeur de données contextuelles
     */
    public function getInitialContext(): array;
}
