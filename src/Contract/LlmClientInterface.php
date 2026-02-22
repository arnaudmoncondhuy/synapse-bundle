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
 * Input $contents :
 *   [
 *     ['role' => 'user',      'content' => '...'],
 *     ['role' => 'assistant', 'content' => '...', 'tool_calls' => [
 *         ['id' => '...', 'type' => 'function', 'function' => ['name' => '...', 'arguments' => '...']]
 *     ]],
 *     ['role' => 'tool', 'tool_call_id' => '...', 'content' => '...'],
 *   ]
 *
 * Chaque client est responsable de convertir ce format vers l'API de son provider.
 *
 * Output (chunks yield par streamGenerateContent) :
 *   [
 *     'text'             => string|null,
 *     'thinking'         => string|null,   // Gemini only, null pour les autres
 *     'function_calls'   => [['id' => string, 'name' => string, 'args' => array]],
 *     'usage'            => [
 *         'promptTokenCount'     => int,
 *         'candidatesTokenCount' => int,
 *         'thoughtsTokenCount'   => int,
 *         'totalTokenCount'      => int,
 *     ],
 *     'safety_ratings'   => array,         // Gemini only, [] pour les autres
 *     'blocked'          => bool,
 *     'blocked_category' => string|null,
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
     * @param string      $systemInstruction Instruction système
     * @param array       $contents          Historique au format Synapse canonical
     * @param array       $tools             Déclarations d'outils (format Synapse)
     * @param string|null $model             Modèle spécifique (override config)
     * @param array       $debugOut          Sortie de debug : sera remplie avec actual_request_params et raw_request_body
     */
    public function streamGenerateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator;

    /**
     * Génère du contenu en mode synchrone.
     * Retourne le dernier chunk normalisé.
     *
     * @param string      $systemInstruction      Instruction système
     * @param array       $contents               Historique au format Synapse canonical
     * @param array       $tools                  Déclarations d'outils (format Synapse)
     * @param string|null $model                  Modèle spécifique (override config)
     * @param array|null  $thinkingConfigOverride Configuration de thinking (Gemini)
     * @param array       $debugOut               Sortie de debug : sera remplie avec actual_request_params et raw_request_body
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
        array &$debugOut = [],
    ): array;
}
