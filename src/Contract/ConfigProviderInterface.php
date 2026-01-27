<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

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
}
