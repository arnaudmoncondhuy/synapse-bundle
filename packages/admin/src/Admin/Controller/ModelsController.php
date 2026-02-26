<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Catalogue des modèles LLM
 *
 * Affiche tous les modèles connus (depuis ModelCapabilityRegistry),
 * avec possibilité de les activer/désactiver et d'ajouter des informations (pricing).
 */
#[Route('/synapse/admin/models')]
class ModelsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseModelRepository $modelRepo,
        private SynapseProviderRepository $providerRepo,
        private SynapsePresetRepository $presetRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    /**
     * Catalogue complet des modèles
     */
    #[Route('', name: 'synapse_admin_models', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        // 1. Récupérer les providers actifs et configurés
        $activeProviders = [];
        foreach ($this->providerRepo->findAll() as $provider) {
            if ($provider->isEnabled() && $provider->isConfigured()) {
                $activeProviders[] = $provider->getName();
            }
        }

        // 2. Modèles en DB (overrides utilisateur)
        $dbModels = [];
        foreach ($this->modelRepo->findAll() as $m) {
            $dbModels[$m->getModelId()] = $m;
        }

        // 3. Construire la liste plate filtrée
        $models = [];
        foreach ($this->capabilityRegistry->getKnownModels() as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);

            // Filtrer par provider actif
            if (!in_array($caps->provider, $activeProviders, true)) {
                continue;
            }

            $dbModel = $dbModels[$modelId] ?? null;

            $models[] = [
                'id'           => $modelId,
                'provider'     => $caps->provider,
                'type'         => $caps->type,
                'currency'     => $caps->provider === 'ovh' ? '€' : '$',
                'capabilities' => $caps,
                'db_entity'    => $dbModel,
                'is_enabled'   => $dbModel ? $dbModel->isEnabled() : true,
                'pricing_input'  => $dbModel?->getPricingInput() ?? $caps->pricingInput,
                'pricing_output' => $dbModel?->getPricingOutput() ?? $caps->pricingOutput,
            ];
        }

        return $this->render('@Synapse/admin/models.html.twig', [
            'models' => $models,
        ]);
    }

    /**
     * Toggle activation d'un modèle
     */
    #[Route('/{modelId}/toggle', name: 'synapse_admin_models_toggle', methods: ['POST'])]
    public function toggle(string $modelId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager);

        $model = $this->modelRepo->findOneBy(['modelId' => $modelId]);
        if ($model === null) {
            // Créer l'entrée DB si elle n'existe pas
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $model = new SynapseModel();
            $model->setModelId($modelId)
                ->setProviderName($caps->provider)
                ->setLabel($modelId)
                ->setIsEnabled(false); // On va l'activer ci-dessous

            $this->em->persist($model);
        }

        $model->setIsEnabled(!$model->isEnabled());
        $this->em->flush();

        $status = $model->isEnabled() ? 'activé' : 'désactivé';
        $this->addFlash('success', 'Modèle "' . $modelId . '" ' . $status . '.');

        return $this->redirectToRoute('synapse_admin_models');
    }

    /**
     * Mise à jour du pricing d'un modèle
     */
    #[Route('/{modelId}/pricing', name: 'synapse_admin_models_pricing', methods: ['POST'])]
    public function updatePricing(string $modelId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager);

        $model = $this->modelRepo->findOneBy(['modelId' => $modelId]);

        if ($model === null) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $model = new SynapseModel();
            $model->setModelId($modelId)
                ->setProviderName($caps->provider)
                ->setLabel($modelId)
                ->setIsEnabled(true);
            $this->em->persist($model);
        }

        $inputRaw  = $request->request->get('pricing_input', '');
        $outputRaw = $request->request->get('pricing_output', '');

        $model->setPricingInput($inputRaw !== '' ? (float) $inputRaw : null);
        $model->setPricingOutput($outputRaw !== '' ? (float) $outputRaw : null);
        $model->setLabel($request->request->get('label', $model->getLabel()));

        $this->em->flush();

        $this->addFlash('success', 'Pricing du modèle "' . $modelId . '" mis à jour.');

        return $this->redirectToRoute('synapse_admin_models');
    }
}
