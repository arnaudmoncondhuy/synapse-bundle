<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Memoire;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Souvenirs — Visualisation des mémoires utilisateur — Admin V2
 *
 * Liste paginée de toutes les mémoires vectorielles (souvenirs confirmés par les utilisateurs).
 * Permet de vérifier que le flux « proposition → confirmation » fonctionne.
 */
#[Route('/synapse/admin-v2/memoire/souvenirs', name: 'synapse_v2_admin_memories')]
class MemoryController extends AbstractController
{
    use AdminSecurityTrait;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly SynapseVectorMemoryRepository $memoryRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $page  = max(1, (int) $request->query->get('page', '1'));
        $limit = self::PER_PAGE;
        $offset = ($page - 1) * $limit;

        $memories = $this->memoryRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );
        $total = $this->memoryRepository->count([]);
        $pages = (int) ceil($total / $limit);

        return $this->render('@Synapse/admin_v2/memoire/memories.html.twig', [
            'memories' => $memories,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'limit'    => $limit,
        ]);
    }

    #[Route('/{id}/supprimer', name: '_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_admin_memories_delete');

        $memory = $this->memoryRepository->find($id);
        if ($memory) {
            $this->memoryRepository->remove($memory, true);
            $this->addFlash('success', 'Souvenir supprimé.');
        } else {
            $this->addFlash('error', 'Souvenir introuvable.');
        }

        return $this->redirectToRoute('synapse_v2_admin_memories', [
            'page' => $request->query->get('page', 1),
        ]);
    }
}
