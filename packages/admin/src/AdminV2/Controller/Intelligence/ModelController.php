<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Catalogue et gestion des modèles LLM - Admin V2
 */
#[Route('/synapse/admin-v2/intelligence/modeles', name: 'synapse_v2_admin_')]
class ModelController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseModelRepository $modelRepo,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    /**
     * Catalogue des modèles
     */
    #[Route('', name: 'models', methods: ['GET'])]
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

        return $this->render('@Synapse/admin_v2/intelligence/models.html.twig', [
            'models' => $models,
        ]);
    }

    /**
     * Toggle d'activation d'un modèle
     */
    #[Route('/{modelId}/toggle', name: 'models_toggle', methods: ['POST'])]
    public function toggle(string $modelId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_model_toggle_' . $modelId);

        $model = $this->modelRepo->findOneBy(['modelId' => $modelId]);
        if ($model === null) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $model = new SynapseModel();
            $model->setModelId($modelId)
                ->setProviderName($caps->provider)
                ->setLabel($modelId)
                ->setIsEnabled(false);

            $this->em->persist($model);
        }

        $model->setIsEnabled(!$model->isEnabled());
        $this->em->flush();

        $status = $model->isEnabled() ? 'activé' : 'désactivé';
        $this->addFlash('success', sprintf('Le modèle "%s" a été %s.', $modelId, $status));

        return $this->redirectToRoute('synapse_v2_admin_configuration_llm', ['tab' => 'modeles']);
    }

    /**
     * Mise à jour du pricing et du libellé
     */
    #[Route('/{modelId}/pricing', name: 'models_pricing', methods: ['POST'])]
    public function pricing(string $modelId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_model_pricing_' . $modelId);

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

        $label = $request->request->get('label', '');
        $inputRaw = $request->request->get('pricing_input', '');
        $outputRaw = $request->request->get('pricing_output', '');

        if ($label !== '') {
            $model->setLabel($label);
        }

        $model->setPricingInput($inputRaw !== '' ? (float) $inputRaw : null);
        $model->setPricingOutput($outputRaw !== '' ? (float) $outputRaw : null);

        $this->em->flush();

        $this->addFlash('success', sprintf('Configuration du modèle "%s" mise à jour.', $modelId));

        return $this->redirectToRoute('synapse_v2_admin_configuration_llm', ['tab' => 'modeles']);
    }
}
