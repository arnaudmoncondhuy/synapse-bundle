<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration Synapse - Édition en temps réel
 */
#[Route('/synapse/admin/config')]
class ConfigController extends AbstractController
{
    public function __construct(
        private SynapseConfigRepository $configRepo
    ) {
    }

    /**
     * Formulaire de configuration
     */
    #[Route('', name: 'synapse_admin_config', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $scope = $request->query->get('scope', 'default');
        $config = $this->configRepo->getConfig($scope);

        if ($request->isMethod('POST')) {
            // Traiter le formulaire
            $data = $request->request->all();

            // Safety Settings
            $config->setSafetyEnabled((bool) ($data['safety_enabled'] ?? false));
            $config->setSafetyDefaultThreshold($data['safety_default_threshold'] ?? null);
            $config->setSafetyHateSpeech($data['safety_hate_speech'] ?? null);
            $config->setSafetyDangerousContent($data['safety_dangerous_content'] ?? null);
            $config->setSafetyHarassment($data['safety_harassment'] ?? null);
            $config->setSafetySexuallyExplicit($data['safety_sexually_explicit'] ?? null);

            // Generation Config
            $config->setGenerationTemperature((float) ($data['generation_temperature'] ?? 1.0));
            $config->setGenerationTopP((float) ($data['generation_top_p'] ?? 0.95));
            $config->setGenerationTopK((int) ($data['generation_top_k'] ?? 40));
            $config->setGenerationMaxOutputTokens(
                !empty($data['generation_max_output_tokens']) ? (int) $data['generation_max_output_tokens'] : null
            );

            // Thinking
            $config->setThinkingEnabled((bool) ($data['thinking_enabled'] ?? true));
            $config->setThinkingBudget((int) ($data['thinking_budget'] ?? 1024));

            // Context Caching
            $config->setContextCachingEnabled((bool) ($data['context_caching_enabled'] ?? false));
            $config->setContextCachingId($data['context_caching_id'] ?? null);

            // System Prompt
            $config->setSystemPrompt($data['system_prompt'] ?? null);

            // Model
            $config->setModel($data['model'] ?? 'gemini-2.0-flash-exp');

            // Vertex AI
            $config->setVertexProjectId($data['vertex_project_id'] ?? null);
            $config->setVertexRegion($data['vertex_region'] ?? 'europe-west1');

            // Persistence
            $config->setPersistenceEnabled((bool) ($data['persistence_enabled'] ?? false));
            $config->setPersistenceHandler($data['persistence_handler'] ?? 'session');

            // Encryption
            $config->setEncryptionEnabled((bool) ($data['encryption_enabled'] ?? false));
            $config->setEncryptionKey($data['encryption_key'] ?? null);

            // Token Tracking
            $config->setTokenTrackingEnabled((bool) ($data['token_tracking_enabled'] ?? false));
            $tokenPricingJson = $data['token_pricing'] ?? '[]';
            $config->setTokenPricing(json_decode($tokenPricingJson, true) ?? []);

            // Risk Detection
            $config->setRiskDetectionEnabled((bool) ($data['risk_detection_enabled'] ?? false));
            $config->setRiskDetectionAutoRegisterTool((bool) ($data['risk_detection_auto_register_tool'] ?? true));

            // Retention
            $config->setRetentionDays((int) ($data['retention_days'] ?? 30));

            // UI & Admin
            $config->setUiSidebarEnabled((bool) ($data['ui_sidebar_enabled'] ?? true));
            $config->setAdminDefaultColor($data['admin_default_color'] ?? '#8b5cf6');
            $config->setAdminDefaultIcon($data['admin_default_icon'] ?? 'robot');

            // Context
            $config->setContextLanguage($data['context_language'] ?? 'fr');
            $config->setContextBaseIdentity($data['context_base_identity'] ?? null);

            $this->configRepo->getEntityManager()->flush();

            $this->addFlash('success', 'Configuration mise à jour avec succès');

            return $this->redirectToRoute('synapse_admin_config', ['scope' => $scope]);
        }

        return $this->render('@Synapse/admin/config.html.twig', [
            'config' => $config,
            'scope' => $scope,
        ]);
    }
}
