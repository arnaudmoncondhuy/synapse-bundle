<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Contrat pour un agent IA orchestrateur.
 *
 * Un agent implémente une tâche complexe impliquant potentiellement plusieurs appels LLM,
 * boucles de raisonnement, ou orchestration de sous-systèmes.
 *
 * À la différence d'un AiTool (fonction simple et rapide), un agent peut :
 * - Durer plusieurs secondes
 * - Effectuer plusieurs appels LLM
 * - Maintenir un état interne
 * - Orchestrer d'autres composants
 */
interface AgentInterface
{
    /**
     * Identifiant unique de l'agent (snake_case).
     * Ex: 'preset_validator', 'conversation_analyzer'
     */
    public function getName(): string;

    /**
     * Description en langage naturel de ce que fait l'agent.
     * Utilisée pour l'UI et potentiellement pour un orchestrateur LLM plus tard.
     */
    public function getDescription(): string;

    /**
     * Exécute l'agent avec les paramètres fournis.
     *
     * @param array $input Paramètres spécifiques à l'agent
     * @return array Résultat structuré
     */
    public function run(array $input): array;
}
