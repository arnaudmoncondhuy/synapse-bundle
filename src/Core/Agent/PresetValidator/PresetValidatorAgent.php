<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Agent\PresetValidator;

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
        private \ArnaudMoncondhuy\SynapseBundle\Service\ModelCapabilityRegistry $capabilityRegistry,
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
     *     'debug_id' => ?string,
     *     'critical_checks' => ['response_not_empty' => bool, 'debug_saved_in_db' => bool],
     *     'all_critical_ok' => bool,
     *     'analysis' => string (Markdown),
     *     'usage_test' => array,
     * }
     */
    public function run(array $input): array
    {
        $preset = $input['preset'];
        if (!$preset instanceof SynapsePreset) {
            throw new \InvalidArgumentException('Input must contain a "preset" key with SynapsePreset instance.');
        }

        // ── 1. Appel de test avec le preset cible ──
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

        // ── 2. Checks critiques PHP ──
        $criticalChecks = [
            'response_not_empty' => !empty($result['answer']),
            'debug_saved_in_db'  => !empty($result['debug_id']),
        ];
        $allCriticalPassed = !in_array(false, $criticalChecks, true);

        // ── 3. Récupérer le debug log ──
        $debugLog = null;
        $debugData = [];
        if (!empty($result['debug_id'])) {
            $debugLog = $this->debugLogRepo->findByDebugId($result['debug_id']);
            $debugData = $debugLog?->getData() ?? [];
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
            'context_caching_supported' => $caps->contextCaching,
            'function_calling_supported' => $caps->functionCalling,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $analysisPrompt = sprintf(
            "Tu es un agent de validation du système Synapse LLM. Analyse les données suivantes.\n\n" .
                "IMPORTANT: Prends d'abord en compte les CAPACITÉS DU MODÈLE testé. Si une capacité (ex: top_k_supported) est false, " .
                "il est NORMAL et ATTENDU que le paramètre soit ignoré et absent de la requête API, ce N'EST PAS une anomalie.\n\n" .
                "## 0. Capacités du modèle cible\n```json\n%s\n```\n\n" .
                "## 1. Configuration ATTENDUE du preset\n```json\n%s\n```\n\n" .
                "## 2. Paramètres NORMALISÉS capturés par Synapse\n```json\n%s\n```\n\n" .
                "## 3. Requête BRUTE envoyée à l'API\n```json\n%s\n```\n\n" .
                "## 4. Réponse BRUTE reçue de l'API\n```json\n%s\n```\n\n" .
                "Retourne un rapport Markdown avec exactement ces 3 sections :\n\n" .
                "### ✅ Points conformes\n(liste des paramètres correctement transmis, ou ignorés à juste titre car non supportés par le modèle)\n\n" .
                "### ⚠️ Anomalies détectées\n(écarts non justifiés entre preset attendu et réalité)\n\n" .
                "### Conclusion\n(1-2 phrases résumant le statut global du preset)",
            $capsJson,
            $presetConfigJson,
            $normalizedParamsJson,
            $rawRequestJson,
            $rawResponseJson,
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
            'preset'          => $preset,
            'ai_response'     => $result['answer'] ?? null,
            'debug_id'        => $result['debug_id'] ?? null,
            'critical_checks' => $criticalChecks,
            'all_critical_ok' => $allCriticalPassed,
            'analysis'        => $analysisResult['answer'] ?? 'Analyse indisponible.',
            'usage_test'      => $debugData['usage'] ?? [],
        ];
    }
}
