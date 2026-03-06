<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Catalogue et gestion des modèles LLM - Administration Synapse.
 */
#[Route('%synapse.admin_prefix%/intelligence/modeles', name: 'synapse_admin_')]
class ModelController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseModelRepository $modelRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    /**
     * Toggle d'activation d'un modèle.
     */
    #[Route('/{modelId}/toggle', name: 'models_toggle', methods: ['POST'])]
    public function toggle(string $modelId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_model_toggle_'.$modelId);

        $model = $this->modelRepo->findOneBy(['modelId' => $modelId]);
        if (null === $model) {
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

        return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'modeles']);
    }

    /**
     * Mise à jour du pricing et du libellé.
     */
    #[Route('/{modelId}/pricing', name: 'models_pricing', methods: ['POST'])]
    public function pricing(string $modelId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_model_pricing_'.$modelId);

        $model = $this->modelRepo->findOneBy(['modelId' => $modelId]);
        if (null === $model) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            $model = new SynapseModel();
            $model->setModelId($modelId)
                ->setProviderName($caps->provider)
                ->setLabel($modelId)
                ->setIsEnabled(true);
            $this->em->persist($model);
        }

        $label = (string) $request->request->get('label', '');
        $inputRaw = (string) $request->request->get('pricing_input', '');
        $outputRaw = (string) $request->request->get('pricing_output', '');

        if ('' !== $label) {
            $model->setLabel($label);
        }

        $model->setPricingInput('' !== $inputRaw ? (float) $inputRaw : null);
        $model->setPricingOutput('' !== $outputRaw ? (float) $outputRaw : null);

        $this->em->flush();

        $this->addFlash('success', sprintf('Configuration du modèle "%s" mise à jour.', $modelId));

        return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'modeles']);
    }
}
