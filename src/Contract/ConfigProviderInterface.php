<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapsePreset;

/**
 * Interface pour fournir la configuration dynamique au GeminiClient.
 *
 * Permet d'injecter des paramètres depuis une source externe (BDD, API, etc.)
 * au lieu d'utiliser uniquement les paramètres statiques de synapse.yaml.
 */
interface ConfigProviderInterface
{
    /**
     * Retourne la configuration dynamique.
     *
     * @return array Configuration au format:
     *               [
     *                   'safety_settings' => [
     *                       'enabled' => bool,
     *                       'default_threshold' => string,
     *                       'thresholds' => [
     *                           'hate_speech' => string,
     *                           'dangerous_content' => string,
     *                           'harassment' => string,
     *                           'sexually_explicit' => string,
     *                       ],
     *                   ],
     *                   'generation_config' => [
     *                       'temperature' => float,
     *                       'top_p' => float,
     *                       'top_k' => int,
     *                       'max_output_tokens' => ?int,
     *                       'stop_sequences' => array,
     *                   ],
     *                   'context_caching' => [
     *                       'enabled' => bool,
     *                       'cached_content_id' => ?string,
     *                   ],
     *               ]
     */
    public function getConfig(): array;

    /**
     * Configure un override temporaire (en mémoire) qui sera retourné par getConfig()
     * au lieu du preset actif en base de données.
     *
     * Utilisé par ChatService pour tester un preset spécifique sans le rendre actif.
     */
    public function setOverride(?array $config): void;

    /**
     * Retourne la configuration complète pour un preset spécifique,
     * sans modifier le preset actif en DB.
     *
     * Utilisé par PresetTestService pour obtenir la config du preset à tester.
     */
    public function getConfigForPreset(SynapsePreset $preset): array;
}
