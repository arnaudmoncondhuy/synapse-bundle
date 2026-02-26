<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Securite;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des clés API — Admin V2
 *
 * Vue centralisée des providers configurés et de leur statut d'authentification.
 * Les clés ne sont jamais affichées en clair — uniquement leur présence/absence.
 */
#[Route('/synapse/admin-v2/securite/cles-api', name: 'synapse_v2_admin_api_keys')]
class ApiKeysController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseProviderRepository $providerRepo,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $providers = $this->providerRepo->findAllOrdered();

        $summary = [
            'total'       => count($providers),
            'configured'  => count(array_filter($providers, fn($p) => $p->isConfigured())),
            'enabled'     => count(array_filter($providers, fn($p) => $p->isEnabled())),
            'missing_key' => count(array_filter($providers, fn($p) => $p->isEnabled() && !$p->isConfigured())),
        ];

        return $this->render('@Synapse/admin_v2/securite/api_keys.html.twig', [
            'providers' => $providers,
            'summary'   => $summary,
        ]);
    }
}
