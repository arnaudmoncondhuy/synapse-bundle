<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Agent\PresetValidator;

use ArnaudMoncondhuy\SynapseBundle\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\DebugLogRepository;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;

/**
 * Agent de validation de preset LLM.
 *
 * Orchestre le test automatique d'un preset en deux appels LLM :
 * 1. Appel de test : message simple avec le preset cible
 * 2. Appel d'analyse : LLM autonome analyse 4 JSONs bruts (config attendue, paramètres normalisés,
 *    requête brute, réponse brute)
 *
 * Produit un rapport Markdown structuré avec ✅ Points conformes, ⚠️ Anomalies, Conclusion.
 */
class PresetValidatorAgent implements AgentInterface
{
    public function __construct(
        private ChatService $chatService,
        private DebugLogRepository $debugLogRepo,
        private \ArnaudMoncondhuy\SynapseBundle\Core\Chat\ModelCapabilityRegistry $capabilityRegistry,
    ) {}

    public function getName(): string
    {
        return 'preset_validator';
    }

    public function getDescription(): string
    {
        return 'Teste un preset LLM et produit un rapport d\'analyse de conformité '
            . '(paramètres envoyés à l\'API vs. configuration attendue).';
    }

    /**
     * @param array $input ['preset' => SynapsePreset]
     *
     * @return array {
     *     'preset' => SynapsePreset,
     *     'ai_response' => ?string,
     *     'ai_response_streaming' => ?string,
     *     'debug_id' => ?string,
     *     'debug_id_streaming' => ?string,
     *     'critical_checks' => ['response_not_empty' => bool, 'debug_saved_in_db' => bool, 'streaming_enabled' => bool, 'streaming_works' => bool],
     *     'all_critical_ok' => bool,
     *     'analysis' => string (Markdown),
     *     'usage_test' => array,
     *     'usage_test_streaming' => array,
     * }
     */
    public function run(array $input): array
    {
        $preset = $input['preset'];
        if (!$preset instanceof SynapsePreset) {
            throw new \InvalidArgumentException('Input must contain a "preset" key with SynapsePreset instance.');
        }

        // ── 1a. Appel de test SYNCHRONE avec le preset cible ──
        $result = $this->chatService->ask(
            'Dis-moi bonjour en une phrase courte.',
            [
                'preset'      => $preset,
                'debug'       => true,
                'stateless'   => true,
                'tools'       => [],
                'conversation_id' => null,
            ]
        );

        // ── 1b. Appel de test STREAMING avec le preset cible (si streaming_enabled) ──
        $resultStreaming = null;
        $streamingWorks = false;
        $presetConfig = $preset->toArray();
        $isStreamingEnabled = $presetConfig['streaming_enabled'] ?? false;

        if ($isStreamingEnabled) {
            try {
                $resultStreaming = $this->chatService->ask(
                    'Dis-moi bonjour en une phrase courte.',
                    [
                        'preset'      => $preset,
                        'debug'       => true,
                        'stateless'   => true,
                        'tools'       => [],
                        'conversation_id' => null,
                        'streaming'   => true,  // Force streaming mode if supported
                    ]
                );
                $streamingWorks = !empty($resultStreaming['answer']);
            } catch (\Throwable $e) {
                // Streaming test failed, but that's OK - it's optional
                $resultStreaming = null;
            }
        }

        // ── 2. Checks critiques PHP ──
        $criticalChecks = [
            'response_not_empty'    => !empty($result['answer']),
            'debug_saved_in_db'     => !empty($result['debug_id']),
            'streaming_enabled'     => $isStreamingEnabled,
            'streaming_works'       => $streamingWorks,
        ];
        $allCriticalPassed = !in_array(false, $criticalChecks, true);

        // ── 3. Récupérer le debug log synchrone ──
        $debugLog = null;
        $debugData = [];
        if (!empty($result['debug_id'])) {
            $debugLog = $this->debugLogRepo->findByDebugId($result['debug_id']);
            $debugData = $debugLog?->getData() ?? [];
        }

        // ── 3b. Récupérer le debug log streaming (si disponible) ──
        $debugDataStreaming = [];
        if (!empty($resultStreaming['debug_id'])) {
            $debugLogStreaming = $this->debugLogRepo->findByDebugId($resultStreaming['debug_id']);
            $debugDataStreaming = $debugLogStreaming?->getData() ?? [];
        }

        // ── 4. Préparer les 4 JSONs pour l'agent d'analyse ──
        $presetConfig = $preset->toArray();
        // Ne pas exposer les credentials au LLM d'analyse
        unset($presetConfig['provider_credentials']);

        $presetConfigJson = json_encode(
            $presetConfig,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $normalizedParamsJson = json_encode(
            $debugData['preset_config'] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $rawRequestJson = json_encode(
            $debugData['raw_request_body'] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        // Réponse brute : raw_api_response en priorité, sinon derniers chunks
        $rawResponse = $debugData['raw_api_response'] ?? $debugData['raw_api_chunks'] ?? [];
        $rawResponseJson = json_encode(
            $rawResponse,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        // ── 5. Récupérer les capacités du modèle testé ──
        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());
        $capsJson = json_encode([
            'thinking_supported' => $caps->thinking,
            'safety_settings_supported' => $caps->safetySettings,
            'top_k_supported' => $caps->topK,
            'function_calling_supported' => $caps->functionCalling,
            'streaming_supported' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Build streaming comparison if available
        $streamingComparisonText = '';
        if ($isStreamingEnabled && !empty($debugDataStreaming)) {
            $streamingUsage = $debugDataStreaming['usage'] ?? [];
            $syncUsage = $debugData['usage'] ?? [];
            $streamingComparisonText = sprintf(
                "\n\n## 5. Comparaison Synchrone vs Streaming\n" .
                    "**Mode synchrone**: Tokens entrée=%d, sortie=%d\n" .
                    "**Mode streaming**: Tokens entrée=%d, sortie=%d\n" .
                    "(Les tokens doivent être identiques ou proches)",
                $syncUsage['promptTokenCount'] ?? 0,
                $syncUsage['candidatesTokenCount'] ?? 0,
                $streamingUsage['promptTokenCount'] ?? 0,
                $streamingUsage['candidatesTokenCount'] ?? 0,
            );
        }

        $analysisPrompt = sprintf(
            "Tu es un agent de validation du système Synapse LLM. Analyse les données suivantes.\n\n" .
                "IMPORTANT: Prends d'abord en compte les CAPACITÉS DU MODÈLE testé. Si une capacité (ex: top_k_supported) est false, " .
                "il est NORMAL et ATTENDU que le paramètre soit ignoré et absent de la requête API, ce N'EST PAS une anomalie.\n\n" .
                "IMPORTANT: Analyse UNIQUEMENT les paramètres configurés dans le preset. Ne mentionne PAS les paramètres absents " .
                "(ex: si context_caching n'est pas configuré, ne le mentionne pas du tout).\n\n" .
                "## 0. Capacités du modèle cible\n```json\n%s\n```\n\n" .
                "## 1. Configuration ATTENDUE du preset\n```json\n%s\n```\n\n" .
                "## 2. Paramètres NORMALISÉS capturés par Synapse\n```json\n%s\n```\n\n" .
                "## 3. Requête BRUTE envoyée à l'API\n```json\n%s\n```\n\n" .
                "## 4. Réponse BRUTE reçue de l'API\n```json\n%s\n```\n%s" .
                "\n\nRetourne un rapport Markdown avec exactement ces 3 sections :\n\n" .
                "### ✅ Points conformes\n(liste des paramètres correctement transmis, ou ignorés à juste titre car non supportés par le modèle)\n\n" .
                "### ⚠️ Anomalies détectées\n(écarts non justifiés entre preset attendu et réalité)\n\n" .
                "### Conclusion\n(1-2 phrases résumant le statut global du preset)",
            $capsJson,
            $presetConfigJson,
            $normalizedParamsJson,
            $rawRequestJson,
            $rawResponseJson,
            $streamingComparisonText,
        );

        // ── 5. Appel agent d'analyse (preset actif, stateless, sans debug) ──
        $analysisResult = $this->chatService->ask(
            $analysisPrompt,
            [
                'stateless' => true,
                'debug'     => false,
                'tools'     => [],
            ]
        );

        return [
            'preset'                    => $preset,
            'ai_response'               => $result['answer'] ?? null,
            'ai_response_streaming'     => $resultStreaming['answer'] ?? null,
            'debug_id'                  => $result['debug_id'] ?? null,
            'debug_id_streaming'        => $resultStreaming['debug_id'] ?? null,
            'critical_checks'           => $criticalChecks,
            'all_critical_ok'           => $allCriticalPassed,
            'analysis'                  => $analysisResult['answer'] ?? 'Analyse indisponible.',
            'usage_test'                => $debugData['usage'] ?? [],
            'usage_test_streaming'      => $debugDataStreaming['usage'] ?? [],
        ];
    }
}
