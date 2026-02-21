<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseBundle\Service\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseBundle\Service\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseBundle\Service\SmartPresetFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des presets de configuration LLM
 *
 * Un preset associe un provider + modèle + paramètres de génération.
 * Un seul preset peut être actif par scope.
 */
#[Route('/synapse/admin/presets')]
class PresetsController extends AbstractController
{
    public function __construct(
        private SynapseConfigRepository $configRepo,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private DatabaseConfigProvider $configProvider,
        private EntityManagerInterface $em,
        private SmartPresetFactory $smartPresetFactory,
    ) {}

    /**
     * Liste de tous les presets
     */
    #[Route('', name: 'synapse_admin_presets', methods: ['GET'])]
    public function index(): Response
    {
        $presets  = $this->configRepo->findAllPresets();
        $providers = $this->providerRepo->findAllOrdered();

        $presetsWithCaps = [];
        foreach ($presets as $preset) {
            $presetsWithCaps[] = [
                'entity' => $preset,
                'caps'   => $this->capabilityRegistry->getCapabilities($preset->getModel()),
            ];
        }

        return $this->render('@Synapse/admin/presets.html.twig', [
            'presets'   => $presetsWithCaps,
            'providers' => $providers,
        ]);
    }

    /**
     * Créer un nouveau preset
     */
    #[Route('/new', name: 'synapse_admin_presets_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $preset = new SynapseConfig();
        $preset->setScope('default');

        if ($request->isMethod('POST')) {
            $this->applyFormData($preset, $request->request->all());
            $this->em->persist($preset);
            $this->em->flush();

            $this->addFlash('success', 'Preset "' . $preset->getName() . '" créé.');

            return $this->redirectToRoute('synapse_admin_presets');
        }

        return $this->render('@Synapse/admin/preset_edit.html.twig', [
            'preset'    => $preset,
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->getFullModelsCapabilities(),
            'safety_thresholds'  => $this->getSafetyThresholds(),
            'smart_presets'      => $this->smartPresetFactory->getAvailablePresets(),
            'is_new'    => true,
        ]);
    }

    /**
     * Éditer un preset existant
     */
    #[Route('/{id}/edit', name: 'synapse_admin_presets_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseConfig $preset, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->applyFormData($preset, $request->request->all());
            $this->em->flush();

            // Invalider le cache du scope modifié
            $this->configProvider->setScope($preset->getScope());
            $this->configProvider->clearCache();

            $this->addFlash('success', 'Preset "' . $preset->getName() . '" mis à jour.');

            return $this->redirectToRoute('synapse_admin_presets');
        }

        return $this->render('@Synapse/admin/preset_edit.html.twig', [
            'preset'    => $preset,
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->getFullModelsCapabilities(),
            'safety_thresholds'  => $this->getSafetyThresholds(),
            'smart_presets'      => $this->smartPresetFactory->getAvailablePresets(),
            'is_new'    => false,
        ]);
    }

    /**
     * Activer un preset (désactive les autres du même scope)
     */
    #[Route('/{id}/activate', name: 'synapse_admin_presets_activate', methods: ['POST'])]
    public function activate(SynapseConfig $preset): Response
    {
        $this->configRepo->activatePreset($preset);

        // Invalider le cache du scope
        $this->configProvider->setScope($preset->getScope());
        $this->configProvider->clearCache();

        $this->addFlash('success', 'Preset "' . $preset->getName() . '" activé pour le scope "' . $preset->getScope() . '".');

        return $this->redirectToRoute('synapse_admin_presets');
    }

    /**
     * Cloner un preset
     */
    #[Route('/{id}/clone', name: 'synapse_admin_presets_clone', methods: ['POST'])]
    public function clone(SynapseConfig $source): Response
    {
        $clone = new SynapseConfig();
        $clone->setName($source->getName() . ' (copie)');
        $clone->setScope($source->getScope());
        $clone->setDescription($source->getDescription());
        $clone->setProviderName($source->getProviderName());
        $clone->setModel($source->getModel());
        $clone->setSafetyEnabled($source->isSafetyEnabled());
        $clone->setSafetyDefaultThreshold($source->getSafetyDefaultThreshold());
        $clone->setSafetyHateSpeech($source->getSafetyHateSpeech());
        $clone->setSafetyDangerousContent($source->getSafetyDangerousContent());
        $clone->setSafetyHarassment($source->getSafetyHarassment());
        $clone->setSafetySexuallyExplicit($source->getSafetySexuallyExplicit());
        $clone->setGenerationTemperature($source->getGenerationTemperature());
        $clone->setGenerationTopP($source->getGenerationTopP());
        $clone->setGenerationTopK($source->getGenerationTopK());
        $clone->setGenerationMaxOutputTokens($source->getGenerationMaxOutputTokens());
        $clone->setGenerationStopSequences($source->getGenerationStopSequences());
        $clone->setThinkingEnabled($source->isThinkingEnabled());
        $clone->setThinkingBudget($source->getThinkingBudget());
        $clone->setContextCachingEnabled($source->isContextCachingEnabled());
        $clone->setContextCachingId($source->getContextCachingId());
        $clone->setRetentionDays($source->getRetentionDays());
        $clone->setContextLanguage($source->getContextLanguage());
        $clone->setSystemPrompt($source->getSystemPrompt());
        $clone->setIsActive(false); // Clone starts inactive

        $this->em->persist($clone);
        $this->em->flush();

        $this->addFlash('success', 'Preset "' . $source->getName() . '" cloné.');

        return $this->redirectToRoute('synapse_admin_presets_edit', ['id' => $clone->getId()]);
    }

    /**
     * Supprimer un preset
     */
    #[Route('/{id}/delete', name: 'synapse_admin_presets_delete', methods: ['POST'])]
    public function delete(SynapseConfig $preset): Response
    {
        if ($preset->isActive()) {
            $this->addFlash('error', 'Impossible de supprimer le preset actif. Activez d\'abord un autre preset.');
            return $this->redirectToRoute('synapse_admin_presets');
        }

        $name = $preset->getName();
        $this->em->remove($preset);
        $this->em->flush();

        $this->addFlash('success', 'Preset "' . $name . '" supprimé.');

        return $this->redirectToRoute('synapse_admin_presets');
    }

    /**
     * Applique les données du formulaire à l'entité preset.
     */
    private function applyFormData(SynapseConfig $preset, array $data): void
    {
        $preset->setName($data['name'] ?? 'Preset');
        $preset->setScope($data['scope'] ?? 'default');
        $preset->setDescription(!empty($data['description']) ? $data['description'] : null);
        $preset->setProviderName($data['provider_name'] ?? 'gemini');
        $preset->setModel($data['model'] ?? 'gemini-2.5-flash');

        // Apply smart preset if selected
        if (!empty($data['smart_preset'])) {
            $data = $this->smartPresetFactory->applyPreset($data['smart_preset'], $data);
        }

        // Safety Settings
        $preset->setSafetyEnabled((bool) ($data['safety_enabled'] ?? false));
        $preset->setSafetyDefaultThreshold($data['safety_default_threshold'] ?? 'BLOCK_MEDIUM_AND_ABOVE');
        $preset->setSafetyHateSpeech(!empty($data['safety_hate_speech']) ? $data['safety_hate_speech'] : null);
        $preset->setSafetyDangerousContent(!empty($data['safety_dangerous_content']) ? $data['safety_dangerous_content'] : null);
        $preset->setSafetyHarassment(!empty($data['safety_harassment']) ? $data['safety_harassment'] : null);
        $preset->setSafetySexuallyExplicit(!empty($data['safety_sexually_explicit']) ? $data['safety_sexually_explicit'] : null);

        // Generation Config
        $preset->setGenerationTemperature((float) ($data['generation_temperature'] ?? 1.0));
        $preset->setGenerationTopP((float) ($data['generation_top_p'] ?? 0.95));
        $preset->setGenerationTopK((int) ($data['generation_top_k'] ?? 40));
        $preset->setGenerationMaxOutputTokens(
            !empty($data['generation_max_output_tokens']) ? (int) $data['generation_max_output_tokens'] : null
        );

        $stopSeqStr = $data['generation_stop_sequences'] ?? '';
        $preset->setGenerationStopSequences(
            array_values(array_filter(array_map('trim', explode(',', $stopSeqStr))))
        );

        // Thinking
        $preset->setThinkingEnabled((bool) ($data['thinking_enabled'] ?? false));
        $preset->setThinkingBudget((int) ($data['thinking_budget'] ?? 1024));

        // Context Caching
        $preset->setContextCachingEnabled((bool) ($data['context_caching_enabled'] ?? false));
        $preset->setContextCachingId(!empty($data['context_caching_id']) ? $data['context_caching_id'] : null);

        // Retention
        $preset->setRetentionDays((int) ($data['retention_days'] ?? 30));

        // Context
        $preset->setContextLanguage($data['context_language'] ?? 'fr');

        // System Prompt
        $preset->setSystemPrompt(!empty($data['system_prompt']) ? $data['system_prompt'] : null);
    }

    private function getModelsByProvider(): array
    {
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $result[$caps->provider][] = $modelId;
        }
        return $result;
    }

    private function getFullModelsCapabilities(): array
    {
        $result = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $result[$modelId] = [
                'provider' => $caps->provider,
                'thinking' => $caps->thinking,
                'safety_settings' => $caps->safetySettings,
                'top_k' => $caps->topK,
                'context_caching' => $caps->contextCaching,
                'function_calling' => $caps->functionCalling,
            ];
        }
        return $result;
    }

    private function getSafetyThresholds(): array
    {
        return [
            'BLOCK_NONE'             => 'Aucun filtre',
            'BLOCK_ONLY_HIGH'        => 'Bloquer seulement haute probabilité',
            'BLOCK_MEDIUM_AND_ABOVE' => 'Bloquer moyenne et haute (Recommandé)',
            'BLOCK_LOW_AND_ABOVE'    => 'Bloquer toute probabilité (Très strict)',
        ];
    }
}
