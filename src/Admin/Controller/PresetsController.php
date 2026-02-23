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

        // Initialize new preset with defaults from active config
        $activeConfig = $this->configProvider->getConfig();
        $preset->setProviderName($activeConfig['provider'] ?? 'gemini');
        $preset->setModel($activeConfig['model'] ?? 'gemini-2.5-flash');

        return $this->render('@Synapse/admin/preset_edit.html.twig', [
            'preset'    => $preset,
            'providers' => $this->providerRepo->findAllOrdered(),
            'models_by_provider' => $this->getModelsByProvider(),
            'model_capabilities' => $this->getFullModelsCapabilities(),
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
        $clone->setGenerationTemperature($source->getGenerationTemperature());
        $clone->setGenerationTopP($source->getGenerationTopP());
        $clone->setGenerationTopK($source->getGenerationTopK());
        $clone->setGenerationMaxOutputTokens($source->getGenerationMaxOutputTokens());
        $clone->setGenerationStopSequences($source->getGenerationStopSequences());
        $clone->setStreamingEnabled($source->isStreamingEnabled());

        // Clone provider-specific options (safety_settings, thinking, etc.)
        $clone->setProviderOptions($source->getProviderOptions());

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
     *
     * Mode hybride: Lit JSON providerOptions depuis le champ caché.
     * Le Controller est 100% agnostique aux options spécifiques des fournisseurs.
     */
    private function applyFormData(SynapsePreset $preset, array $data): void
    {
        // Get defaults from active config
        $activeConfig = $this->configProvider->getConfig();
        $defaultProvider = $activeConfig['provider'] ?? 'gemini';
        $defaultModel = $activeConfig['model'] ?? 'gemini-2.5-flash';

        $preset->setName($data['name'] ?? 'Preset');
        $preset->setProviderName($data['provider_name'] ?? $defaultProvider);

        $modelName = $data['model'] ?? $defaultModel;
        $preset->setModel($modelName);

        // Parse JSON providerOptions (from hidden field synchronized by JS)
        $providerOptions = [];
        if (!empty($data['provider_options'])) {
            try {
                $providerOptions = json_decode($data['provider_options'], associative: true) ?? [];
            } catch (\Throwable) {
                $providerOptions = [];
            }
        }

        // Validate provider options
        $providerOptions = $this->validateProviderOptions($providerOptions, $preset->getProviderName());

        $preset->setProviderOptions($providerOptions);

        // Get model capabilities for generation config validation
        $caps = $this->capabilityRegistry->getCapabilities($modelName);

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

    /**
     * Valide et assainit les options du provider selon sa configuration
     */
    private function validateProviderOptions(array $options, string $providerName): array
    {
        // Énumérations valides
        $validBlockLevels = ['BLOCK_NONE', 'BLOCK_ONLY_HIGH', 'BLOCK_MEDIUM_AND_ABOVE', 'BLOCK_LOW_AND_ABOVE'];
        $validReasoningEfforts = ['low', 'medium', 'high'];

        if ($providerName === 'gemini') {
            // Valider thinking.budget
            if (isset($options['thinking']['budget']) && !empty($options['thinking']['budget'])) {
                $budget = (int) $options['thinking']['budget'];
                // Plage: 128–24576
                if ($budget < 128 || $budget > 24576) {
                    $options['thinking']['budget'] = 1024; // Réinitialiser à la valeur par défaut
                }
            }

            // Valider safety_settings.default_threshold
            if (isset($options['safety_settings']['default_threshold']) && !empty($options['safety_settings']['default_threshold'])) {
                if (!in_array($options['safety_settings']['default_threshold'], $validBlockLevels, true)) {
                    unset($options['safety_settings']['default_threshold']);
                }
            }

            // Valider les 4 filtres de sécurité spécifiques
            $safetyFilters = ['hate_speech', 'dangerous_content', 'harassment', 'sexually_explicit'];
            foreach ($safetyFilters as $filter) {
                if (isset($options['safety_settings']['thresholds'][$filter]) && !empty($options['safety_settings']['thresholds'][$filter])) {
                    $value = $options['safety_settings']['thresholds'][$filter];
                    // Autoriser les valeurs vides ou valides
                    if ($value !== '' && !in_array($value, $validBlockLevels, true)) {
                        unset($options['safety_settings']['thresholds'][$filter]);
                    }
                }
            }
        } elseif ($providerName === 'ovh') {
            // Valider thinking.reasoning_effort
            if (isset($options['thinking']['reasoning_effort']) && !empty($options['thinking']['reasoning_effort'])) {
                if (!in_array($options['thinking']['reasoning_effort'], $validReasoningEfforts, true)) {
                    unset($options['thinking']['reasoning_effort']);
                }
            }

            // Supprimer les champs Gemini qui ne doivent pas être là
            if (isset($options['thinking']['budget'])) {
                unset($options['thinking']['budget']);
            }
            if (isset($options['safety_settings'])) {
                unset($options['safety_settings']);
            }
        }

        return $options;
    }

}
