<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Gestion complète des presets LLM — Administration Synapse.
 *
 * Un preset associe un provider + modèle + paramètres de génération.
 * Un seul preset peut être actif à la fois (le reste est inactif).
 */
#[Route('%synapse.admin_prefix%/intelligence/presets', name: 'synapse_admin_')]
class PresetController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapsePresetRepository $presetRepo,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private DatabaseConfigProvider $configProvider,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private CacheInterface $cache,
        private PresetValidatorAgent $presetValidatorAgent,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    /**
     * Vérifie si un preset est valide (provider configuré + modèle existe).
     */
    private function isPresetValid(SynapsePreset $preset): bool
    {
        // Vérifier que le provider et le modèle sont définis
        $providerName = $preset->getProviderName();
        $model = $preset->getModel();

        if (empty($providerName) || empty($model)) {
            return false;
        }

        // Vérifier que le provider est configuré
        $provider = $this->providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider || !$provider->isConfigured()) {
            return false;
        }

        // Vérifier que le modèle existe dans la registry
        return $this->capabilityRegistry->isKnownModel($model);
    }

    /**
     * Retourne la raison pour laquelle un preset est invalide.
     */
    private function getPresetInvalidReason(SynapsePreset $preset): ?string
    {
        $providerName = $preset->getProviderName();
        $model = $preset->getModel();

        if (empty($providerName) || empty($model)) {
            if (empty($providerName) && empty($model)) {
                return 'Pas de provider ou de modèle configuré';
            }

            return empty($providerName) ? 'Aucun fournisseur défini' : 'Aucun modèle défini';
        }

        $provider = $this->providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider) {
            return 'Fournisseur "'.$providerName.'" introuvable';
        }
        if (!$provider->isConfigured()) {
            return 'Fournisseur "'.$provider->getLabel().'" non configuré';
        }

        if (!$this->capabilityRegistry->isKnownModel($model)) {
            return 'Modèle "'.$model.'" inexistant ou désactivé';
        }

        return null;
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'presets_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $preset = new SynapsePreset();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_edit');
            $this->applyFormData($preset, $request->request->all());
            $this->em->persist($preset);
            $this->em->flush();
            $this->configProvider->clearCache();

            $this->addFlash('success', sprintf('Preset "%s" créé avec succès.', $preset->getName()));

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        // Pré-remplir avec les valeurs de config active
        $activeConfig = $this->configProvider->getConfig();
        $preset->setProviderName(is_string($activeConfig['provider'] ?? null) ? $activeConfig['provider'] : 'gemini');
        $preset->setModel(is_string($activeConfig['model'] ?? null) ? $activeConfig['model'] : 'gemini-2.5-flash');

        return $this->render('@Synapse/admin/intelligence/preset_edit.html.twig', [
            'preset' => $preset,
            'is_new' => true,
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->getFullModelsCapabilities(),
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'presets_edit', methods: ['GET', 'POST'])]
    public function edit(SynapsePreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_edit');
            $this->applyFormData($preset, $request->request->all());
            $this->em->flush();
            $this->configProvider->clearCache();

            $this->addFlash('success', sprintf('Preset "%s" mis à jour.', $preset->getName()));

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        return $this->render('@Synapse/admin/intelligence/preset_edit.html.twig', [
            'preset' => $preset,
            'is_new' => false,
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->getFullModelsCapabilities(),
        ]);
    }

    // ─── Activation ────────────────────────────────────────────────────────────

    #[Route('/{id}/activate', name: 'presets_activate', methods: ['POST'])]
    public function activate(SynapsePreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_activate_'.$preset->getId());

        // 🛡️ DÉFENSE : Vérifier que le preset est valide avant activation
        if (!$this->isPresetValid($preset)) {
            $this->addFlash('error', sprintf(
                'Impossible d\'activer le preset "%s" : %s',
                $preset->getName(),
                $this->getPresetInvalidReason($preset)
            ));

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        $this->presetRepo->activate($preset);
        $this->configProvider->clearCache();

        $this->addFlash('success', sprintf(
            'Le preset "%s" est désormais actif sur l\'ensemble du système.',
            $preset->getName()
        ));

        return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
    }

    // ─── Clone ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/cloner', name: 'presets_clone', methods: ['POST'])]
    public function clone(SynapsePreset $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_clone_'.$source->getId());

        $clone = new SynapsePreset();
        $clone->setName($source->getName().' (copie)');
        $clone->setProviderName($source->getProviderName());
        $clone->setModel($source->getModel());
        $clone->setGenerationTemperature($source->getGenerationTemperature());
        $clone->setGenerationTopP($source->getGenerationTopP());
        $clone->setGenerationTopK($source->getGenerationTopK());
        $clone->setGenerationMaxOutputTokens($source->getGenerationMaxOutputTokens());
        $clone->setGenerationStopSequences($source->getGenerationStopSequences());
        $clone->setStreamingEnabled($source->isStreamingEnabled());
        $clone->setProviderOptions($source->getProviderOptions());
        $clone->setIsActive(false);

        $this->em->persist($clone);
        $this->em->flush();
        $this->configProvider->clearCache();

        $this->addFlash('success', sprintf('Preset "%s" cloné avec succès.', $source->getName()));

        return $this->redirectToRoute('synapse_admin_presets_edit', ['id' => $clone->getId()]);
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'presets_delete', methods: ['POST'])]
    public function delete(SynapsePreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_delete_'.$preset->getId());

        if ($preset->isActive()) {
            $this->addFlash('error', 'Impossible de supprimer le preset actif. Activez d\'abord un autre preset.');

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        $name = $preset->getName();
        $this->em->remove($preset);
        $this->em->flush();
        $this->configProvider->clearCache();

        $this->addFlash('success', sprintf('Preset "%s" supprimé.', $name));

        return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
    }

    // ─── Test (Messenger polling) ───────────────────────────────────────────────

    /**
     * Lance un test de validation du preset via PresetValidatorAgent.
     * Retourne la page d'attente du test.
     */
    #[Route('/{id}/tester', name: 'presets_test', methods: ['POST'])]
    public function test(SynapsePreset $preset): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $cacheKey = sprintf('synapse_preset_test_%d', $preset->getId());

        $this->cache->delete($cacheKey);

        $callback = function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return ['status' => 'pending', 'progress' => 0, 'report' => null];
        };
        $this->cache->get($cacheKey, $callback);

        return $this->render('@Synapse/admin/intelligence/preset_test_waiting.html.twig', [
            'preset' => $preset,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Polling endpoint — premier appel exécute tous les steps, les suivants lisent le cache.
     *
     * Architecture : Exécution unique sur le premier poll (status='pending').
     * Les appels LLM peuvent durer 60–120s ; on évite les requêtes concurrentes et les locks
     * en concentrant toute l'exécution dans une seule requête HTTP longue.
     */
    #[Route('/{id}/tester/statut', name: 'presets_test_status', methods: ['GET'])]
    public function testStatus(SynapsePreset $preset): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $cacheKey = sprintf('synapse_preset_test_%d', $preset->getId());
        /** @var array{status: string, report: array<string, mixed>|null, progress?: int}|null $data */
        $data = $this->cache->get($cacheKey, fn () => null);

        if (!$data) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }

        // Premier poll : le test n'a pas encore démarré → exécuter les 3 étapes maintenant
        if ('pending' === $data['status']) {
            // Permettre jusqu'à 5 minutes pour les appels LLM potentiellement lents
            set_time_limit(300);

            try {
                $report = $this->presetValidatorAgent->runAll($preset);

                $data['status'] = 'completed';
                $data['report'] = $report;
            } catch (\Throwable $e) {
                // L'agent a levé une exception non gérée en interne
                $data['status'] = 'completed';
                $data['report'] = [
                    'sync_error' => $e->getMessage(),
                    'all_critical_ok' => false,
                    'analysis' => 'Test interrompu par une exception critique : '.$e->getMessage(),
                    'critical_checks' => ['response_not_empty' => false, 'debug_saved_in_db' => false],
                    'config_checks' => [],
                    'config_errors' => [],
                    'config_ok' => false,
                    'preset_info' => ['name' => $preset->getName()],
                ];
            }

            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($data): array {
                $item->expiresAfter(3600);

                return $data;
            });
        }

        // Rapport disponible → renvoyer le template HTML complet
        if ('completed' === $data['status']) {
            return $this->render('@Synapse/admin/intelligence/preset_test_result.html.twig', [
                'preset' => $preset,
                'report' => $data['report'],
            ]);
        }

        // Test toujours en attente (ne devrait arriver qu'en cas de race condition)
        return new JsonResponse(['status' => $data['status'], 'progress' => $data['progress'] ?? 0]);
    }

    // ─── Helpers privés ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function applyFormData(SynapsePreset $preset, array $data): void
    {
        $activeConfig = $this->configProvider->getConfig();
        $defaultProvider = is_string($activeConfig['provider'] ?? null) ? $activeConfig['provider'] : 'gemini';
        $defaultModel = is_string($activeConfig['model'] ?? null) ? $activeConfig['model'] : 'gemini-2.5-flash';

        $nameVal = $data['name'] ?? 'Preset';
        $preset->setName(is_string($nameVal) ? $nameVal : 'Preset');

        $providerNameVal = $data['provider_name'] ?? $defaultProvider;
        $preset->setProviderName(is_string($providerNameVal) ? $providerNameVal : $defaultProvider);

        $modelName = $data['model'] ?? $defaultModel;
        $modelNameStr = is_string($modelName) ? $modelName : $defaultModel;
        $preset->setModel($modelNameStr);

        $providerOptions = [];
        $rawOptions = $data['provider_options'] ?? null;
        if (is_string($rawOptions) && !empty($rawOptions)) {
            try {
                $decoded = json_decode($rawOptions, associative: true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $providerOptions = $decoded;
                }
            } catch (\Throwable) {
                $providerOptions = [];
            }
        }

        $caps = $this->capabilityRegistry->getCapabilities($modelNameStr);
        $providerOptions = $this->validateProviderOptions($preset->getProviderName(), $providerOptions, $caps);

        $preset->setProviderOptions($providerOptions);
        $temp = $data['generation_temperature'] ?? 1.0;
        $preset->setGenerationTemperature(is_numeric($temp) ? (float) $temp : 1.0);

        $topP = $data['generation_top_p'] ?? 0.95;
        $preset->setGenerationTopP(is_numeric($topP) ? (float) $topP : 0.95);

        if ($caps->topK) {
            $topK = $data['generation_top_k'] ?? 40;
            $preset->setGenerationTopK(is_numeric($topK) ? (int) $topK : 40);
        } else {
            $preset->setGenerationTopK(null);
        }

        $maxTokens = $data['generation_max_output_tokens'] ?? null;
        $preset->setGenerationMaxOutputTokens(
            !empty($maxTokens) && is_numeric($maxTokens) ? (int) $maxTokens : null
        );

        $stopSeqRaw = $data['generation_stop_sequences'] ?? '';
        $stopSeqStr = is_string($stopSeqRaw) ? $stopSeqRaw : '';
        $preset->setGenerationStopSequences(
            array_values(array_filter(array_map('trim', explode(',', $stopSeqStr))))
        );

        $preset->setStreamingEnabled(!empty($data['streaming_enabled']));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function getModelsByProvider(): array
    {
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            if ('embedding' !== $caps->type) {
                $result[$caps->provider][] = $modelId;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{provider: string, type: string, dimensions: int[], thinking: bool, safetySettings: bool, topK: bool, functionCalling: bool, streaming: bool}>
     */
    private function getFullModelsCapabilities(): array
    {
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $result[$modelId] = [
                'provider' => $caps->provider,
                'type' => $caps->type,
                'dimensions' => $caps->dimensions,
                'thinking' => $caps->thinking,
                'safetySettings' => $caps->safetySettings,
                'topK' => $caps->topK,
                'functionCalling' => $caps->functionCalling,
                'streaming' => $caps->streaming,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function validateProviderOptions(string $providerName, array $options, ModelCapabilities $caps): array
    {
        $validBlockLevels = ['BLOCK_NONE', 'BLOCK_ONLY_HIGH', 'BLOCK_MEDIUM_AND_ABOVE', 'BLOCK_LOW_AND_ABOVE'];
        $validReasoningEfforts = ['low', 'medium', 'high'];

        if (!$caps->thinking) {
            unset($options['thinking']);
        }
        if (!$caps->safetySettings) {
            unset($options['safety_settings']);
        }

        if ('gemini' === $providerName) {
            if ($caps->thinking && isset($options['thinking']) && is_array($options['thinking']) && !empty($options['thinking']['budget'])) {
                $budget = is_numeric($options['thinking']['budget']) ? (int) $options['thinking']['budget'] : 0;
                if ($budget < 128 || $budget > 32000) {
                    $options['thinking']['budget'] = 1024;
                }
            }
            if ($caps->safetySettings && isset($options['safety_settings']) && is_array($options['safety_settings']) && !empty($options['safety_settings']['default_threshold'])) {
                $defaultThreshold = $options['safety_settings']['default_threshold'];
                if (!is_string($defaultThreshold) || !in_array($defaultThreshold, $validBlockLevels, true)) {
                    unset($options['safety_settings']['default_threshold']);
                }
            }
            foreach (['hate_speech', 'dangerous_content', 'harassment', 'sexually_explicit'] as $filter) {
                if (isset($options['safety_settings']) && is_array($options['safety_settings']) && isset($options['safety_settings']['thresholds']) && is_array($options['safety_settings']['thresholds']) && isset($options['safety_settings']['thresholds'][$filter])) {
                    $value = $options['safety_settings']['thresholds'][$filter];
                    if (is_string($value) && '' !== $value && !in_array($value, $validBlockLevels, true)) {
                        unset($options['safety_settings']['thresholds'][$filter]);
                    }
                }
            }
        } elseif ('ovh' === $providerName) {
            if ($caps->thinking && isset($options['thinking']) && is_array($options['thinking']) && !empty($options['thinking']['reasoning_effort'])) {
                $effort = $options['thinking']['reasoning_effort'];
                if (!is_string($effort) || !in_array($effort, $validReasoningEfforts, true)) {
                    unset($options['thinking']['reasoning_effort']);
                }
            }
            if (isset($options['thinking']) && is_array($options['thinking'])) {
                unset($options['thinking']['budget']);
            }
            unset($options['safety_settings']);
        }

        return $options;
    }
}
