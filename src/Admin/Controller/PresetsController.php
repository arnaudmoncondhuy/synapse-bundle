<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseBundle\Core\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ModelCapabilityRegistry;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des presets de configuration LLM
 *
 * Un preset associe un provider + modèle + paramètres de génération.
 * Un seul preset peut être actif à la fois.
 */
#[Route('/synapse/admin/presets')]
class PresetsController extends AbstractController
{
    public function __construct(
        private SynapsePresetRepository $presetRepo,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private DatabaseConfigProvider $configProvider,
        private EntityManagerInterface $em,

    ) {}

    /**
     * Liste de tous les presets
     */
    #[Route('', name: 'synapse_admin_presets', methods: ['GET'])]
    public function index(): Response
    {
        $presets  = $this->presetRepo->findAllPresets();
        $providers = $this->providerRepo->findAllOrdered();

        $presetsWithCaps = [];
        foreach ($presets as $preset) {
            $presetsWithCaps[] = [
                'entity' => $preset,
                'caps'   => $this->capabilityRegistry->getCapabilities($preset->getModel()),
            ];
        }

        // Sort: Active first
        usort($presetsWithCaps, function ($a, $b) {
            if ($a['entity']->isActive()) return -1;
            if ($b['entity']->isActive()) return 1;
            return 0;
        });

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
        $preset = new SynapsePreset();

        if ($request->isMethod('POST')) {
            $this->applyFormData($preset, $request->request->all());
            $this->em->persist($preset);
            $this->em->flush();

            $this->configProvider->clearCache();

            $this->addFlash('success', 'Preset "' . $preset->getName() . '" créé.');

            return $this->redirectToRoute('synapse_admin_presets');
        }

        return $this->render('@Synapse/admin/preset_edit.html.twig', [
            'preset'    => $preset,
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->getFullModelsCapabilities(),
            'safety_thresholds'  => $this->getSafetyThresholds(),

            'is_new'    => true,
        ]);
    }

    /**
     * Éditer un preset existant
     */
    #[Route('/{id}/edit', name: 'synapse_admin_presets_edit', methods: ['GET', 'POST'])]
    public function edit(SynapsePreset $preset, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->applyFormData($preset, $request->request->all());
            $this->em->flush();

            // Invalider le cache
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

            'is_new'    => false,
        ]);
    }

    /**
     * Activer un preset (désactive tous les autres)
     */
    #[Route('/{id}/activate', name: 'synapse_admin_presets_activate', methods: ['POST'])]
    public function activate(SynapsePreset $preset): Response
    {
        $this->presetRepo->activate($preset);

        // Invalider le cache
        $this->configProvider->clearCache();

        $this->addFlash('success', 'Preset "' . $preset->getName() . '" activé.');

        return $this->redirectToRoute('synapse_admin_presets');
    }

    /**
     * Cloner un preset
     */
    #[Route('/{id}/clone', name: 'synapse_admin_presets_clone', methods: ['POST'])]
    public function clone(SynapsePreset $source): Response
    {
        $clone = new SynapsePreset();
        $clone->setName($source->getName() . ' (copie)');
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
        $clone->setReasoningEffort($source->getReasoningEffort());
        $clone->setStreamingEnabled($source->isStreamingEnabled());
        $clone->setIsActive(false); // Clone starts inactive

        $this->em->persist($clone);
        $this->em->flush();

        $this->configProvider->clearCache();

        $this->addFlash('success', 'Preset "' . $source->getName() . '" cloné.');

        return $this->redirectToRoute('synapse_admin_presets_edit', ['id' => $clone->getId()]);
    }

    /**
     * Supprimer un preset
     */
    #[Route('/{id}/delete', name: 'synapse_admin_presets_delete', methods: ['POST'])]
    public function delete(SynapsePreset $preset): Response
    {
        if ($preset->isActive()) {
            $this->addFlash('error', 'Impossible de supprimer le preset actif. Activez d\'abord un autre preset.');
            return $this->redirectToRoute('synapse_admin_presets');
        }

        $name = $preset->getName();
        $this->em->remove($preset);
        $this->em->flush();

        $this->configProvider->clearCache();

        $this->addFlash('success', 'Preset "' . $name . '" supprimé.');

        return $this->redirectToRoute('synapse_admin_presets');
    }

    /**
     * Applique les données du formulaire à l'entité preset.
     */
    private function applyFormData(SynapsePreset $preset, array $data): void
    {
        $preset->setName($data['name'] ?? 'Preset');
        $preset->setProviderName($data['provider_name'] ?? 'gemini');

        $modelName = $data['model'] ?? 'gemini-2.5-flash';
        $preset->setModel($modelName);


        // Récupération des capacités du modèle pour nettoyer les données non supportées
        $caps = $this->capabilityRegistry->getCapabilities($modelName);

        $providerOptions = [];

        // Safety Settings
        $safetyEnabled = (bool) ($data['safety_enabled'] ?? false);
        if ($caps->safetySettings && $safetyEnabled) {
            $providerOptions['safety_settings'] = [
                'enabled'           => true,
                'default_threshold' => $data['safety_default_threshold'] ?? 'BLOCK_MEDIUM_AND_ABOVE',
                'thresholds'        => array_filter([
                    'hate_speech'       => !empty($data['safety_hate_speech']) ? $data['safety_hate_speech'] : null,
                    'dangerous_content' => !empty($data['safety_dangerous_content']) ? $data['safety_dangerous_content'] : null,
                    'harassment'        => !empty($data['safety_harassment']) ? $data['safety_harassment'] : null,
                    'sexually_explicit' => !empty($data['safety_sexually_explicit']) ? $data['safety_sexually_explicit'] : null,
                ]),
            ];
        }

        // Thinking / Reasoning
        $thinkingEnabled = (bool) ($data['thinking_enabled'] ?? false);
        if ($caps->thinking && $thinkingEnabled) {
            $providerOptions['thinking'] = [
                'enabled'          => true,
                'budget'           => (int) ($data['thinking_budget'] ?? 1024),
                'reasoning_effort' => $data['reasoning_effort'] ?? 'high',
            ];
        }

        $preset->setProviderOptions($providerOptions);

        // Generation Config
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

        // Streaming Mode (checkbox non envoyé = false)
        $preset->setStreamingEnabled(!empty($data['streaming_enabled']));
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
