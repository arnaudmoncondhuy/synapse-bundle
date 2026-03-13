<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Memoire;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Souvenirs — Visualisation des mémoires utilisateur — Administration Synapse.
 *
 * Liste paginée de toutes les mémoires vectorielles (souvenirs confirmés par les utilisateurs).
 * Permet de vérifier que le flux « proposition → confirmation » fonctionne.
 */
#[Route('%synapse.admin_prefix%/memoire', name: 'synapse_admin_')]
class MemoryController extends AbstractController
{
    use AdminSecurityTrait;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly SynapseVectorMemoryRepository $memoryRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly TranslatorInterface $translator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('/souvenirs', name: 'memories', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $page = max(1, (int) $request->query->get('page', '1'));
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

        return $this->render('@Synapse/admin/memoire/memories.html.twig', [
            'memories' => $memories,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ]);
    }

    #[Route('/documents', name: 'documents', methods: ['GET'])]
    public function documents(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        return $this->render('@Synapse/admin/shared/placeholder.html.twig', [
            'page_title' => $this->translator->trans('synapse.admin.sidebar.documents', [], 'synapse_admin'),
            'icon' => 'files',
            'breadcrumb_section' => $this->translator->trans('synapse.admin.sidebar.section.memory', [], 'synapse_admin'),
            'coming_soon_message' => $this->translator->trans('synapse.admin.memory.documents.coming_soon', [], 'synapse_admin'),
        ]);
    }

    #[Route('/{id}/supprimer', name: '_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_memories_delete');

        $memory = $this->memoryRepository->find($id);
        if ($memory) {
            $this->memoryRepository->remove($memory, true);
            $this->addFlash('success', $this->translator->trans('synapse.admin.memory.flash.deleted', [], 'synapse_admin'));
        } else {
            $this->addFlash('error', $this->translator->trans('synapse.admin.memory.flash.not_found', [], 'synapse_admin'));
        }

        return $this->redirectToRoute('synapse_admin_memories', [
            'page' => $request->query->get('page', 1),
        ]);
    }
}
