<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\DatabaseConfigProvider;
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
 * Gestion complète des presets LLM — Admin V2
 *
 * Un preset associe un provider + modèle + paramètres de génération.
 * Un seul preset peut être actif à la fois (le reste est inactif).
 */
#[Route('/synapse/admin-v2/intelligence/presets', name: 'synapse_v2_admin_presets')]
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
    ) {}

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $presets = $this->presetRepo->findAllPresets();

        $presetsWithCaps = array_map(
            fn(SynapsePreset $p) => [
                'entity' => $p,
                'caps'   => $this->capabilityRegistry->getCapabilities($p->getModel()),
            ],
            $presets
        );

        // Tri : preset actif toujours en tête de liste
        usort($presetsWithCaps, fn($a, $b) => $b['entity']->isActive() <=> $a['entity']->isActive());

        return $this->render('@Synapse/admin_v2/intelligence/presets.html.twig', [
            'presets' => $presetsWithCaps,
        ]);
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $preset = new SynapsePreset();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_preset_edit');
            $this->applyFormData($preset, $request->request->all());
            $this->em->persist($preset);
            $this->em->flush();
            $this->configProvider->clearCache();

            $this->addFlash('success', sprintf('Preset "%s" créé avec succès.', $preset->getName()));
            return $this->redirectToRoute('synapse_v2_admin_presets');
        }

        // Pré-remplir avec les valeurs de config active
        $activeConfig = $this->configProvider->getConfig();
        $preset->setProviderName($activeConfig['provider'] ?? 'gemini');
        $preset->setModel($activeConfig['model'] ?? 'gemini-2.5-flash');

        return $this->render('@Synapse/admin_v2/intelligence/preset_edit.html.twig', [
            'preset'              => $preset,
            'is_new'              => true,
            'providers'           => $this->providerRepo->findAllOrdered(),
            'models_by_provider'  => $this->getModelsByProvider(),
            'model_capabilities'  => $this->getFullModelsCapabilities(),
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: '_edit', methods: ['GET', 'POST'])]
    public function edit(SynapsePreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_preset_edit');
            $this->applyFormData($preset, $request->request->all());
            $this->em->flush();
            $this->configProvider->clearCache();

            $this->addFlash('success', sprintf('Preset "%s" mis à jour.', $preset->getName()));
            return $this->redirectToRoute('synapse_v2_admin_presets');
        }

        return $this->render('@Synapse/admin_v2/intelligence/preset_edit.html.twig', [
            'preset'              => $preset,
            'is_new'              => false,
            'providers'           => $this->providerRepo->findAllOrdered(),
            'models_by_provider'  => $this->getModelsByProvider(),
            'model_capabilities'  => $this->getFullModelsCapabilities(),
        ]);
    }

    // ─── Activation ────────────────────────────────────────────────────────────

    #[Route('/{id}/activate', name: '_activate', methods: ['POST'])]
    public function activate(SynapsePreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_preset_activate_' . $preset->getId());

        $this->presetRepo->activate($preset);
        $this->configProvider->clearCache();

        $this->addFlash('success', sprintf(
            'Le preset "%s" est désormais actif sur l\'ensemble du système.',
            $preset->getName()
        ));

        return $this->redirectToRoute('synapse_v2_admin_presets');
    }

    // ─── Clone ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/cloner', name: '_clone', methods: ['POST'])]
    public function clone(SynapsePreset $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_preset_clone_' . $source->getId());

        $clone = new SynapsePreset();
        $clone->setName($source->getName() . ' (copie)');
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
        return $this->redirectToRoute('synapse_v2_admin_presets_edit', ['id' => $clone->getId()]);
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'])]
    public function delete(SynapsePreset $preset, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_preset_delete_' . $preset->getId());

        if ($preset->isActive()) {
            $this->addFlash('error', 'Impossible de supprimer le preset actif. Activez d\'abord un autre preset.');
            return $this->redirectToRoute('synapse_v2_admin_presets');
        }

        $name = $preset->getName();
        $this->em->remove($preset);
        $this->em->flush();
        $this->configProvider->clearCache();

        $this->addFlash('success', sprintf('Preset "%s" supprimé.', $name));
        return $this->redirectToRoute('synapse_v2_admin_presets');
    }

    // ─── Test (Messenger polling) ───────────────────────────────────────────────

    /**
     * Lance un test de validation du preset via PresetValidatorAgent.
     * Retourne la page d'attente du test.
     */
    #[Route('/{id}/tester', name: '_test', methods: ['POST'])]
    public function test(SynapsePreset $preset): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $cacheKey = sprintf('synapse_preset_test_%d', $preset->getId());

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600);
            return ['status' => 'pending', 'progress' => 0, 'report' => null];
        });

        return $this->render('@Synapse/admin_v2/intelligence/preset_test_waiting.html.twig', [
            'preset'    => $preset,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Polling endpoint — renvoie le statut courant ou le rapport final.
     */
    #[Route('/{id}/tester/statut', name: '_test_status', methods: ['GET'])]
    public function testStatus(SynapsePreset $preset): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        set_time_limit(0);

        $cacheKey = sprintf('synapse_preset_test_%d', $preset->getId());
        $lockKey  = sprintf('synapse_test_lock_%d', $preset->getId());

        $data = $this->cache->get($cacheKey, fn() => null);

        if (!$data) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }

        // Polling actif si en attente ou en cours
        if (in_array($data['status'], ['pending', 'processing'], true)) {
            $isLocked = $this->cache->get($lockKey, fn() => false);

            if (!$isLocked) {
                $this->cache->get($lockKey, function (ItemInterface $item) {
                    $item->expiresAfter(60);
                    return true;
                });

                try {
                    $data = $this->cache->get($cacheKey, fn() => null);
                    $currentStep = 0;
                    if ($data['status'] === 'pending') {
                        $currentStep = 1;
                    } elseif ($data['status'] === 'processing') {
                        if ($data['progress'] < 33)       $currentStep = 1;
                        elseif ($data['progress'] < 66)   $currentStep = 2;
                        elseif ($data['progress'] < 100)  $currentStep = 3;
                    }

                    if ($currentStep > 0) {
                        $data['message'] = $this->presetValidatorAgent->getStepLabel($currentStep);
                        $this->cache->delete($cacheKey);
                        $this->cache->get($cacheKey, function (ItemInterface $item) use ($data) {
                            $item->expiresAfter(3600);
                            return $data;
                        });

                        if (function_exists('fastcgi_finish_request')) {
                            $data['is_processing_async'] = true;
                            $response = new JsonResponse($data);
                            $response->prepare(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
                            $response->send();
                            if (PHP_SAPI !== 'cli') {
                                ignore_user_abort(true);
                                fastcgi_finish_request();
                            }
                        }

                        $report = $data['report'] ?? [];
                        $this->presetValidatorAgent->runStep($currentStep, $preset, $report);

                        $data['status']   = ($currentStep === 3) ? 'completed' : 'processing';
                        $data['progress'] = match ($currentStep) {
                            1 => 33,
                            2 => 66,
                            3 => 100,
                            default => $data['progress']
                        };
                        $data['message']  = null;
                        $data['report']   = $report;
                        unset($data['is_processing_async']);

                        $this->cache->delete($cacheKey);
                        $this->cache->get($cacheKey, function (ItemInterface $item) use ($data) {
                            $item->expiresAfter(3600);
                            return $data;
                        });

                        if (isset($data['is_processing_async'])) {
                            exit;
                        }
                    }
                } finally {
                    $this->cache->delete($lockKey);
                }
            }
        }

        if ($data['status'] === 'completed') {
            return $this->render('@Synapse/admin_v2/intelligence/preset_test_result.html.twig', [
                'report' => $data['report'],
            ]);
        }

        if ($data['status'] === 'error') {
            return new Response(
                '<div class="sv2-alert sv2-alert--danger">Erreur : ' . htmlspecialchars($data['error'] ?? 'Inconnue') . '</div>'
            );
        }

        return new JsonResponse($data);
    }

    // ─── Helpers privés ────────────────────────────────────────────────────────

    private function applyFormData(SynapsePreset $preset, array $data): void
    {
        $activeConfig    = $this->configProvider->getConfig();
        $defaultProvider = $activeConfig['provider'] ?? 'gemini';
        $defaultModel    = $activeConfig['model'] ?? 'gemini-2.5-flash';

        $preset->setName($data['name'] ?? 'Preset');
        $preset->setProviderName($data['provider_name'] ?? $defaultProvider);

        $modelName = $data['model'] ?? $defaultModel;
        $preset->setModel($modelName);

        $providerOptions = [];
        if (!empty($data['provider_options'])) {
            try {
                $providerOptions = json_decode($data['provider_options'], associative: true, flags: JSON_THROW_ON_ERROR) ?? [];
            } catch (\Throwable) {
                $providerOptions = [];
            }
        }

        $caps            = $this->capabilityRegistry->getCapabilities($modelName);
        $providerOptions = $this->validateProviderOptions($providerOptions, $preset->getProviderName(), $caps);

        $preset->setProviderOptions($providerOptions);
        $preset->setGenerationTemperature((float) ($data['generation_temperature'] ?? 1.0));
        $preset->setGenerationTopP((float) ($data['generation_top_p'] ?? 0.95));

        if ($caps->topK) {
            $preset->setGenerationTopK((int) ($data['generation_top_k'] ?? 40));
        } else {
            $preset->setGenerationTopK(null);
        }

        $preset->setGenerationMaxOutputTokens(
            !empty($data['generation_max_output_tokens']) ? (int) $data['generation_max_output_tokens'] : null
        );

        $stopSeqStr = $data['generation_stop_sequences'] ?? '';
        $preset->setGenerationStopSequences(
            array_values(array_filter(array_map('trim', explode(',', $stopSeqStr))))
        );

        $preset->setStreamingEnabled(!empty($data['streaming_enabled']));
    }

    private function getModelsByProvider(): array
    {
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            if ($caps->type !== 'embedding') {
                $result[$caps->provider][] = $modelId;
            }
        }
        return $result;
    }

    private function getFullModelsCapabilities(): array
    {
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $result[$modelId] = [
                'provider'        => $caps->provider,
                'type'            => $caps->type,
                'dimensions'      => $caps->dimensions,
                'thinking'        => $caps->thinking,
                'safetySettings'  => $caps->safetySettings,
                'topK'            => $caps->topK,
                'functionCalling' => $caps->functionCalling,
                'streaming'       => $caps->streaming,
            ];
        }
        return $result;
    }

    private function validateProviderOptions(array $options, string $providerName, ModelCapabilities $caps): array
    {
        $validBlockLevels      = ['BLOCK_NONE', 'BLOCK_ONLY_HIGH', 'BLOCK_MEDIUM_AND_ABOVE', 'BLOCK_LOW_AND_ABOVE'];
        $validReasoningEfforts = ['low', 'medium', 'high'];

        if (!$caps->thinking) {
            unset($options['thinking']);
        }
        if (!$caps->safetySettings) {
            unset($options['safety_settings']);
        }

        if ($providerName === 'gemini') {
            if ($caps->thinking && isset($options['thinking']['budget']) && !empty($options['thinking']['budget'])) {
                $budget = (int) $options['thinking']['budget'];
                if ($budget < 128 || $budget > 32000) {
                    $options['thinking']['budget'] = 1024;
                }
            }
            if ($caps->safetySettings && isset($options['safety_settings']['default_threshold']) && !empty($options['safety_settings']['default_threshold'])) {
                if (!in_array($options['safety_settings']['default_threshold'], $validBlockLevels, true)) {
                    unset($options['safety_settings']['default_threshold']);
                }
            }
            foreach (['hate_speech', 'dangerous_content', 'harassment', 'sexually_explicit'] as $filter) {
                if (isset($options['safety_settings']['thresholds'][$filter])) {
                    $value = $options['safety_settings']['thresholds'][$filter];
                    if ($value !== '' && !in_array($value, $validBlockLevels, true)) {
                        unset($options['safety_settings']['thresholds'][$filter]);
                    }
                }
            }
        } elseif ($providerName === 'ovh') {
            if ($caps->thinking && isset($options['thinking']['reasoning_effort']) && !empty($options['thinking']['reasoning_effort'])) {
                if (!in_array($options['thinking']['reasoning_effort'], $validReasoningEfforts, true)) {
                    unset($options['thinking']['reasoning_effort']);
                }
            }
            unset($options['thinking']['budget'], $options['safety_settings']);
        }

        return $options;
    }
}
