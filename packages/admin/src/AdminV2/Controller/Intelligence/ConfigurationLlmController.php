<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hub "Configuration LLM" — regroupe Fournisseurs, Modèles et Presets en onglets.
 *
 * Route unique : /synapse/admin-v2/intelligence/configuration-llm?tab=fournisseurs|modeles|presets
 * Les actions POST (toggle, activate, etc.) restent dans leurs controllers respectifs
 * et redirigent ici après traitement.
 */
#[Route('/synapse/admin-v2/intelligence', name: 'synapse_v2_admin_')]
class ConfigurationLlmController extends AbstractController
{
    use AdminSecurityTrait;

    private const VALID_TABS = ['fournisseurs', 'modeles', 'presets'];

    public function __construct(
        private SynapseProviderRepository $providerRepo,
        private SynapsePresetRepository $presetRepo,
        private SynapseModelRepository $modelRepo,
        private LlmClientRegistry $clientRegistry,
        private ModelCapabilityRegistry $capabilityRegistry,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private PresetValidator $presetValidator,
    ) {}

    #[Route('/configuration-llm', name: 'configuration_llm', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tab = $request->query->get('tab', 'fournisseurs');
        if (!in_array($tab, self::VALID_TABS, true)) {
            $tab = 'fournisseurs';
        }

        // ── Données Fournisseurs ─────────────────────────────────────────────
        $providers = $this->providerRepo->findAllOrdered();

        // Synchronisation auto des providers disponibles
        $existingNames = array_map(fn($p) => $p->getName(), $providers);
        $changed = false;
        foreach ($this->clientRegistry->getAvailableProviders() as $name) {
            if (!in_array($name, $existingNames, true)) {
                $client = $this->clientRegistry->getClientByProvider($name);
                $provider = new SynapseProvider();
                $provider->setName($name)
                    ->setLabel($client->getDefaultLabel())
                    ->setIsEnabled(false);
                $this->em->persist($provider);
                $providers[] = $provider;
                $changed = true;
            }
        }
        if ($changed) {
            $this->em->flush();
        }

        // 🛡️ DÉFENSE CRITIQUE : S'assurer que le preset actif est valide
        // Si un preset valide existe et est actif, vérifier son intégrité
        $activePreset = $this->presetRepo->findActive();
        if ($activePreset !== null) {
            try {
                $this->presetValidator->ensureActivePresetIsValid($activePreset);
                // Rafraîchir les données depuis la BDD après la correction potentielle
                $this->em->refresh($activePreset);
            } catch (\Exception $e) {
                // Ignorer l'exception ici - elle sera loggée mais l'admin page s'affichera quand même
                // Le cache de configuration sera invalidé pour que la prochaine utilisation le recharge
            }
        }

        $allPresets = $this->presetRepo->findAllPresets();
        $presetCountByProvider = [];
        $providersByName = [];
        foreach ($providers as $provider) {
            $presetCountByProvider[$provider->getId()] = 0;
            $providersByName[$provider->getName()] = $provider->getId();
        }
        foreach ($allPresets as $preset) {
            $providerName = $preset->getProviderName();
            if ($providerName && isset($providersByName[$providerName])) {
                $presetCountByProvider[$providersByName[$providerName]]++;
            }
        }

        // ── Données Modèles ──────────────────────────────────────────────────
        $activeProviders = [];
        foreach ($providers as $provider) {
            if ($provider->isEnabled() && $provider->isConfigured()) {
                $activeProviders[] = $provider->getName();
            }
        }

        $dbModels = [];
        foreach ($this->modelRepo->findAll() as $m) {
            $dbModels[$m->getModelId()] = $m;
        }

        $models = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            if (!in_array($caps->provider, $activeProviders, true)) {
                continue;
            }
            $dbModel = $dbModels[$modelId] ?? null;
            $models[] = [
                'id'             => $modelId,
                'provider'       => $caps->provider,
                'type'           => $caps->type,
                'currency'       => $caps->provider === 'ovh' ? '€' : '$',
                'capabilities'   => $caps,
                'db_entity'      => $dbModel,
                'is_enabled'     => $dbModel ? $dbModel->isEnabled() : true,
                'pricing_input'  => $dbModel?->getPricingInput() ?? $caps->pricingInput,
                'pricing_output' => $dbModel?->getPricingOutput() ?? $caps->pricingOutput,
                'label'          => $dbModel?->getLabel() ?? $modelId,
            ];
        }

        // ── Données Presets ──────────────────────────────────────────────────
        $presetsWithCaps = array_map(
            fn($p) => [
                'entity' => $p,
                'caps'   => $this->capabilityRegistry->getCapabilities($p->getModel()),
                'isValid' => $this->isPresetValid($p),
                'invalidReason' => $this->getPresetInvalidReason($p),
            ],
            $allPresets
        );
        usort($presetsWithCaps, fn($a, $b) => $b['entity']->isActive() <=> $a['entity']->isActive());

        return $this->render('@Synapse/admin_v2/intelligence/configuration_llm.html.twig', [
            'tab'                    => $tab,
            'providers'              => $providers,
            'preset_count_by_provider' => $presetCountByProvider,
            'models'                 => $models,
            'presets'                => $presetsWithCaps,
        ]);
    }

    /**
     * Vérifie si un preset est valide (provider configuré + modèle existe)
     */
    private function isPresetValid($preset): bool
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
     * Retourne la raison pour laquelle un preset est invalide
     */
    private function getPresetInvalidReason($preset): ?string
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
            return 'Fournisseur "' . $providerName . '" introuvable';
        }
        if (!$provider->isConfigured()) {
            return 'Fournisseur "' . $provider->getLabel() . '" non configuré';
        }

        if (!$this->capabilityRegistry->isKnownModel($model)) {
            return 'Modèle "' . $model . '" inexistant ou désactivé';
        }

        return null;
    }
}
