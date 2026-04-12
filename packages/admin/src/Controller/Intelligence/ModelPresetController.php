<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\CandidateScanner;
use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\HeuristicRecommender;
use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\PresetArchitect;
use ArnaudMoncondhuy\SynapseCore\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Engine\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\CannotActivateException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\DeactivationCascade;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
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
class ModelPresetController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly SynapseProviderRepository $providerRepo,
        private readonly SynapseModelRepository $modelRepo,
        private readonly SynapseAgentRepository $agentRepo,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly LlmClientRegistry $llmRegistry,
        private readonly DatabaseConfigProvider $configProvider,
        private readonly EntityManagerInterface $em,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly CacheInterface $cache,
        private readonly PresetValidatorAgent $presetValidatorAgent,
        private readonly PresetValidator $presetValidator,
        private readonly CandidateScanner $candidateScanner,
        private readonly HeuristicRecommender $heuristicRecommender,
        private readonly PresetArchitect $presetArchitect,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    /**
     * Helper privé : affiche un flash warning par niveau non-vide d'un cascade.
     * Le paramètre $because permet de contextualiser le « pourquoi » dans chaque
     * message (ex: « à la suite de la suppression du preset X »), sinon le flash
     * reste générique si le cascade provient d'un point d'entrée non identifié.
     */
    private function flashCascade(DeactivationCascade $cascade, string $because = ''): void
    {
        $suffix = '' !== $because ? ' '.$because : '';
        if (!empty($cascade->presets)) {
            $this->addFlash('warning', sprintf('Presets désactivés%s : %s', $suffix, implode(', ', $cascade->presets)));
        }
        if (!empty($cascade->agents)) {
            $this->addFlash('warning', sprintf('Agents désactivés%s : %s', $suffix, implode(', ', $cascade->agents)));
        }
        if (!empty($cascade->workflows)) {
            $this->addFlash('warning', sprintf('Workflows désactivés%s : %s', $suffix, implode(', ', $cascade->workflows)));
        }
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'presets_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $preset = new SynapseModelPreset();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_edit');
            $this->applyFormData($preset, $request->request->all());
            $this->em->persist($preset);
            $this->em->flush();
            $this->configProvider->clearCache();

            $this->addFlash('success', sprintf('Model Preset "%s" créé avec succès.', $preset->getName()));

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        // Pré-remplir avec les valeurs de config active
        $activeConfig = $this->configProvider->getConfig();
        $defaultProvider = '' !== $activeConfig->provider ? $activeConfig->provider : ($this->llmRegistry->getAvailableProviders()[0] ?? '');
        $preset->setProviderName($defaultProvider);
        $preset->setModel('' !== $activeConfig->model ? $activeConfig->model : $this->capabilityRegistry->getFirstModelForProvider($defaultProvider));

        return $this->render('@Synapse/admin/intelligence/preset_edit.html.twig', array_merge([
            'preset' => $preset,
            'is_new' => true,
            'is_valid' => $this->presetValidator->isValid($preset),
            'invalid_reason' => $this->presetValidator->getInvalidReason($preset),
        ], $this->getPresetEditTemplateData()));
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'presets_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseModelPreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_edit');
            $this->applyFormData($preset, $request->request->all());

            // Validation AVANT le flush — si l'édition rend le preset invalide,
            // on déclenche la cascade (agents → workflows) sur l'entité encore
            // en mémoire puis on ne fait qu'UN SEUL flush atomique.
            $cascade = DeactivationCascade::empty();
            if (!$this->presetValidator->isValid($preset)) {
                $cascade = $this->agentRepo->deactivateAllByModelPreset($preset);
            }

            $cascade = $this->em->wrapInTransaction(function () use ($cascade): DeactivationCascade {
                $this->em->flush();

                return $cascade;
            });
            $this->configProvider->clearCache();

            $this->flashCascade($cascade, sprintf(
                'à la suite de la mise à jour du preset « %s »',
                $preset->getName()
            ));

            $this->addFlash('success', sprintf('Model Preset "%s" mis à jour.', $preset->getName()));

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        return $this->render('@Synapse/admin/intelligence/preset_edit.html.twig', array_merge([
            'preset' => $preset,
            'is_new' => false,
            'is_valid' => $this->presetValidator->isValid($preset),
            'invalid_reason' => $this->presetValidator->getInvalidReason($preset),
        ], $this->getPresetEditTemplateData()));
    }

    // ─── Activation ────────────────────────────────────────────────────────────

    #[Route('/{id}/activate', name: 'presets_activate', methods: ['POST'])]
    public function activate(SynapseModelPreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_activate_'.$preset->getId());

        // La validation d'activation est encapsulée dans presetRepo->activate().
        try {
            $this->presetRepo->activate($preset);
        } catch (CannotActivateException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        $this->configProvider->clearCache();

        $this->addFlash('success', sprintf(
            'Le Model Preset "%s" est désormais actif sur l\'ensemble du système.',
            $preset->getName()
        ));

        return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
    }

    // ─── Clone ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/cloner', name: 'presets_clone', methods: ['POST'])]
    public function clone(SynapseModelPreset $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_clone_'.$source->getId());

        $clone = new SynapseModelPreset();
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

        $this->addFlash('success', sprintf('Model Preset "%s" cloné avec succès.', $source->getName()));

        return $this->redirectToRoute('synapse_admin_presets_edit', ['id' => $clone->getId()]);
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'presets_delete', methods: ['POST'])]
    public function delete(SynapseModelPreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_delete_'.$preset->getId());

        if ($preset->isActive()) {
            $this->addFlash('error', 'Impossible de supprimer le Model Preset actif. Activez d\'abord un autre Model Preset.');

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
        }

        // Cascade : déléguer à AgentRepo la propagation vers agents +
        // workflows avant le remove. Sans ça, Doctrine ne fait que passer la
        // FK des agents à NULL (onDelete: SET NULL) et ils se retrouveraient
        // silencieusement à router vers le preset actif global.
        $name = $preset->getName();
        $cascade = $this->em->wrapInTransaction(function () use ($preset): DeactivationCascade {
            $cascade = $this->agentRepo->deactivateAllByModelPreset($preset);
            $this->em->remove($preset);
            $this->em->flush();

            return $cascade;
        });
        $this->configProvider->clearCache();

        $this->flashCascade($cascade, sprintf('à la suite de la suppression du preset « %s »', $name));
        $this->addFlash('success', sprintf('Model Preset "%s" supprimé.', $name));

        return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'presets']);
    }

    // ─── Test (Messenger polling) ───────────────────────────────────────────────

    /**
     * Lance un test de validation du preset via PresetValidatorAgent.
     * Retourne la page d'attente du test.
     */
    #[Route('/{id}/tester', name: 'presets_test', methods: ['POST'])]
    public function test(SynapseModelPreset $preset): Response
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
    public function testStatus(SynapseModelPreset $preset): Response
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
    private function applyFormData(SynapseModelPreset $preset, array $data): void
    {
        $activeConfig = $this->configProvider->getConfig();
        $defaultProvider = '' !== $activeConfig->provider ? $activeConfig->provider : ($this->llmRegistry->getAvailableProviders()[0] ?? '');
        $defaultModel = '' !== $activeConfig->model ? $activeConfig->model : $this->capabilityRegistry->getFirstModelForProvider($defaultProvider);

        $nameVal = $data['name'] ?? 'Model Preset';
        $preset->setName(is_string($nameVal) ? $nameVal : 'Model Preset');

        $keyVal = $data['key'] ?? null;
        if (is_string($keyVal)) {
            $preset->setKey(trim($keyVal));
        }

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

        if ($caps->supportsTopK) {
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

    // ─── Wizard ─────────────────────────────────────────────────────────────────

    #[Route('/wizard', name: 'presets_wizard', methods: ['GET'])]
    public function wizard(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $hasActivePreset = null !== $this->presetRepo->findOneBy(['isActive' => true]);

        return $this->render('@Synapse/admin/intelligence/preset_wizard.html.twig', [
            'has_active_preset' => $hasActivePreset,
        ]);
    }

    #[Route('/wizard/generate', name: 'presets_wizard_generate', methods: ['POST'])]
    public function wizardGenerate(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_wizard');
        set_time_limit(120);

        $data = $request->request->all();
        $useCase = (string) ($data['use_case'] ?? 'conversation');
        $priority = (string) ($data['priority'] ?? 'balanced');
        $rgpdSafe = !empty($data['rgpd_safe']);
        $aiMode = !empty($data['ai_mode']);
        $aiDescription = (string) ($data['ai_description'] ?? '');

        // Mode IA : déléguer au LLM
        if ($aiMode && '' !== $aiDescription) {
            try {
                $recommendation = $this->presetGeneratorAgent->generate($aiDescription, requireLlm: true);

                return $this->render('@Synapse/admin/intelligence/preset_wizard.html.twig', [
                    'has_active_preset' => true,
                    'recommendation' => $recommendation->toArray(),
                    'step' => 'result',
                    'form_data' => $data,
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Mode IA indisponible : '.$e->getMessage().'. Basculement en mode guidé.');
            }
        }

        // Mode guidé : heuristique
        $requiredCapability = match ($useCase) {
            'agents' => 'function_calling',
            'image' => 'image_generation',
            'embedding' => 'embedding',
            default => 'text_generation',
        };

        $preferredRange = ModelRange::fromString($priority);

        $candidates = $this->candidateScanner->scan(
            requiredCapability: $requiredCapability,
            rgpdSensitive: $rgpdSafe,
        );

        // Pour les agents, on veut aussi du text_generation
        if ('agents' === $useCase) {
            $candidates = array_values(array_filter(
                $candidates,
                fn ($c) => $c['capabilities']->supportsTextGeneration,
            ));
        }

        if ([] === $candidates) {
            $this->addFlash('error', 'Aucun modèle disponible pour ce type d\'usage. Vérifiez vos providers.');

            return $this->redirectToRoute('synapse_admin_presets_wizard');
        }

        $recommendation = $this->heuristicRecommender->recommend($candidates, $preferredRange);

        return $this->render('@Synapse/admin/intelligence/preset_wizard.html.twig', [
            'has_active_preset' => null !== $this->presetRepo->findOneBy(['isActive' => true]),
            'recommendation' => $recommendation->toArray(),
            'step' => 'result',
            'form_data' => $data,
        ]);
    }

    #[Route('/wizard/create', name: 'presets_wizard_create', methods: ['POST'])]
    public function wizardCreate(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_preset_wizard_create');

        $data = $request->request->all();

        $preset = new SynapseModelPreset();
        $preset->setName((string) ($data['name'] ?? 'Preset généré'));
        $preset->setKey((string) ($data['key'] ?? 'generated'));
        $preset->setProviderName((string) ($data['provider'] ?? ''));
        $preset->setModel((string) ($data['model'] ?? ''));
        $preset->setGenerationTemperature(is_numeric($data['temperature'] ?? null) ? (float) $data['temperature'] : 1.0);
        $preset->setGenerationTopP(is_numeric($data['top_p'] ?? null) ? (float) $data['top_p'] : 0.95);
        $preset->setGenerationTopK(is_numeric($data['top_k'] ?? null) ? (int) $data['top_k'] : null);
        $preset->setGenerationMaxOutputTokens(is_numeric($data['max_output_tokens'] ?? null) ? (int) $data['max_output_tokens'] : null);
        $preset->setStreamingEnabled(!empty($data['streaming_enabled']));

        // Provider options (thinking)
        $providerOptionsRaw = $data['provider_options'] ?? null;
        if (is_string($providerOptionsRaw) && '' !== $providerOptionsRaw) {
            $decoded = json_decode($providerOptionsRaw, true);
            if (is_array($decoded)) {
                $preset->setProviderOptions($decoded);
            }
        }

        $this->em->persist($preset);
        $this->em->flush();

        // Activer si demandé
        $activate = !empty($data['activate']);
        if ($activate) {
            try {
                $this->presetRepo->activate($preset);
                $this->configProvider->clearCache();
                $this->addFlash('success', sprintf('Preset « %s » créé et activé comme preset par défaut.', $preset->getName()));
            } catch (CannotActivateException $e) {
                $this->addFlash('warning', sprintf('Preset créé mais impossible de l\'activer : %s', $e->getMessage()));
            }
        } else {
            $this->addFlash('success', sprintf('Preset « %s » créé avec succès.', $preset->getName()));
        }

        return $this->render('@Synapse/admin/intelligence/preset_wizard.html.twig', [
            'has_active_preset' => true,
            'step' => 'created',
            'created_preset_id' => $preset->getId(),
            'created_preset_name' => $preset->getName(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPresetEditTemplateData(): array
    {
        $providerSchemas = [];
        foreach ($this->llmRegistry->getAvailableProviders() as $name) {
            $client = $this->llmRegistry->getClientByProvider($name);
            $providerSchemas[$name] = $client->getProviderOptionsSchema();
        }

        return [
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->capabilityRegistry->getAllCapabilitiesMap(),
            'provider_schemas' => $providerSchemas,
            'provider_meta' => $this->llmRegistry->getProvidersMeta(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function getModelsByProvider(): array
    {
        $disabled = array_flip($this->modelRepo->findDisabledModelIds());
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            if (isset($disabled[$modelId])) {
                continue;
            }
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $result[$caps->provider][] = $modelId;
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
        try {
            $client = $this->llmRegistry->getClientByProvider($providerName);

            return $client->validateProviderOptions($options, $caps);
        } catch (\RuntimeException) {
            return $options;
        }
    }
}
