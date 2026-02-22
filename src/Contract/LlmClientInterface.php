<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Contrat pour tout client LLM intégré dans Synapse.
 *
 * Permet de brancher n'importe quel provider (Gemini, OVH AI, OpenAI, Mistral…)
 * sans modifier ChatService.
 *
 * ═══════════════════════════════════════════════════════
 * FORMAT INTERNE SYNAPSE (OpenAI canonical)
 * ═══════════════════════════════════════════════════════
 *
 * Input $contents (OpenAI format, le système est le PREMIER message) :
 *   [
 *     ['role' => 'system',    'content' => '...'],  // First message = system instruction
 *     ['role' => 'user',      'content' => '...'],
 *     ['role' => 'assistant', 'content' => '...', 'tool_calls' => [
 *         ['id' => '...', 'type' => 'function', 'function' => ['name' => '...', 'arguments' => '...']]
 *     ]],
 *     ['role' => 'tool', 'tool_call_id' => '...', 'content' => '...'],
 *   ]
 *
 * Chaque client est responsable de convertir ce format vers l'API de son provider.
 * Par exemple, GeminiClient extrait le message système et le place dans systemInstruction Gemini.
 *
 * Output (chunks yield par streamGenerateContent) :
 *   [
 *     'text'             => string|null,
 *     'thinking'         => string|null,   // Réflexion brute (si supporté)
 *     'function_calls'   => [['id' => string, 'name' => string, 'args' => array]],
 *     'usage'            => [
 *         'prompt_tokens'     => int,
 *         'completion_tokens'   => int,
 *         'thinking_tokens'   => int,
 *         'total_tokens'      => int,
 *     ],
 *     'safety_ratings'   => array,         // [], ou scores de sécurité si supporté
 *     'blocked'          => bool,
 *     'blocked_reason'   => string|null,   // Raison lisible de bloquage
 *   ]
 */
interface LlmClientInterface
{
    /**
     * Identifiant du provider (ex : 'gemini', 'ovh').
     * Doit correspondre à la clé dans synapse.providers.* du YAML.
     */
    public function getProviderName(): string;

    /**
     * Génère du contenu en mode streaming.
     * Yield des chunks normalisés (voir format ci-dessus).
     *
     * @param array       $contents          Historique au format OpenAI canonical (inclut le message système en tête)
     * @param array       $tools             Déclarations d'outils (format Synapse)
     * @param string|null $model             Modèle spécifique (override config)
     * @param array       $debugOut          Sortie de debug : sera remplie avec actual_request_params et raw_request_body
     */
    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator;

    /**
     * Génère du contenu en mode synchrone.
     * Retourne le dernier chunk normalisé.
     *
     * @param array       $contents     Historique au format OpenAI canonical (inclut le message système en tête)
     * @param array       $tools        Déclarations d'outils (format Synapse)
     * @param string|null $model        Modèle spécifique (override config)
     * @param array       $options      Options additionnelles (ex: thinking_config, safety_settings)
     * @param array       $debugOut     Sortie de debug : sera remplie avec actual_request_params et raw_request_body
     */
    public function generateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array $options = [],
        array &$debugOut = [],
    ): array;
}
