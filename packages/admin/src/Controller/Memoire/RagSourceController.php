<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Memoire;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Message\ReindexRagSourceMessage;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagDocumentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sources RAG — Administration des bases de connaissance — Administration Synapse.
 */
#[Route('%synapse.admin_prefix%/memoire/rag', name: 'synapse_admin_')]
class RagSourceController extends AbstractController
{
    use AdminSecurityTrait;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly SynapseRagSourceRepository $sourceRepository,
        private readonly SynapseRagDocumentRepository $documentRepository,
        private readonly RagManager $ragManager,
        private readonly RagSourceRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly TranslatorInterface $translator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    // ─── Liste des sources ──────────────────────────────────────────────────────

    #[Route('', name: 'rag_sources', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $sources = $this->sourceRepository->findAllOrdered();
        $registeredSlugs = array_keys($this->registry->getAll());

        return $this->render('@Synapse/admin/memoire/rag_sources.html.twig', [
            'sources' => $sources,
            'registered_slugs' => $registeredSlugs,
        ]);
    }

    // ─── Nouvelle source ────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'rag_sources_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $source = new SynapseRagSource();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_edit');
            $this->applyFormData($source, $request->request->all());
            $this->em->persist($source);
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('synapse.admin.rag.flash.created', ['name' => $source->getName()], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_rag_sources');
        }

        return $this->render('@Synapse/admin/memoire/rag_source_edit.html.twig', [
            'source' => $source,
            'is_new' => true,
            'has_provider' => false,
        ]);
    }

    // ─── Édition ────────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'rag_sources_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_edit');
            $this->applyFormData($source, $request->request->all());
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('synapse.admin.rag.flash.updated', ['name' => $source->getName()], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_rag_sources');
        }

        return $this->render('@Synapse/admin/memoire/rag_source_edit.html.twig', [
            'source' => $source,
            'is_new' => false,
            'has_provider' => $this->registry->has($source->getSlug()),
        ]);
    }

    // ─── Documents d'une source ─────────────────────────────────────────────────

    #[Route('/{id}/documents', name: 'rag_sources_documents', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documents(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;

        $documents = $this->documentRepository->findBySource($source, self::PER_PAGE, $offset);
        $total = $this->documentRepository->countBySource($source);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        return $this->render('@Synapse/admin/memoire/rag_documents.html.twig', [
            'source' => $source,
            'documents' => $documents,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    // ─── Réindexation ───────────────────────────────────────────────────────────

    #[Route('/{id}/reindex', name: 'rag_sources_reindex', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reindex(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_reindex_'.$source->getId());

        if (!$this->registry->has($source->getSlug())) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.rag.flash.no_provider', ['slug' => $source->getSlug()], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_rag_sources_edit', ['id' => $source->getId()]);
        }

        if ('indexing' === $source->getIndexingStatus()) {
            $this->addFlash('warning', $this->translator->trans('synapse.admin.rag.flash.already_indexing', ['name' => $source->getName()], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_rag_sources_edit', ['id' => $source->getId()]);
        }

        $source->setIndexingStatus('indexing');
        $source->setLastError(null);
        $this->em->flush();

        $this->bus->dispatch(new ReindexRagSourceMessage($source->getId() ?? throw new \InvalidArgumentException('RAG source must be persisted')));

        $this->addFlash('success', $this->translator->trans('synapse.admin.rag.flash.reindex_queued', ['name' => $source->getName()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_rag_sources_edit', ['id' => $source->getId()]);
    }

    // ─── Statut temps réel ──────────────────────────────────────────────────────

    #[Route('/{id}/status', name: 'rag_sources_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(SynapseRagSource $source): JsonResponse
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        return $this->json([
            'status' => $source->getIndexingStatus(),
            'documentCount' => $source->getDocumentCount(),
            'totalFiles' => $source->getTotalFiles(),
            'processedFiles' => $source->getProcessedFiles(),
            'lastError' => $source->getLastError(),
            'lastIndexedAt' => $source->getLastIndexedAt()?->format('d/m/Y H:i'),
        ]);
    }

    // ─── Toggle actif/inactif ───────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'rag_sources_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_toggle_'.$source->getId());

        $source->setIsActive(!$source->isActive());
        $this->em->flush();

        $stateKey = $source->isActive() ? 'synapse.admin.rag.flash.enabled' : 'synapse.admin.rag.flash.disabled';
        $this->addFlash('success', $this->translator->trans($stateKey, ['name' => $source->getName()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_rag_sources');
    }

    // ─── Suppression ────────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'rag_sources_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_delete_'.$source->getId());

        $name = $source->getName();
        $this->em->remove($source);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('synapse.admin.rag.flash.deleted', ['name' => $name], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_rag_sources');
    }

    // ─── Test recherche ─────────────────────────────────────────────────────────

    // ─── Vider l'index (sans supprimer la source) ──────────────────────────

    #[Route('/{id}/purge', name: 'rag_sources_purge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function purge(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_purge_'.$source->getId());

        // Supprimer tous les documents de cette source
        $this->documentRepository->deleteBySource($source);

        // Réinitialiser les compteurs sur la source
        $source->setDocumentCount(0);
        $source->setTotalFiles(0);
        $source->setProcessedFiles(0);
        $source->setIndexingStatus('pending');
        $source->setLastError(null);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('synapse.admin.rag.flash.index_purged', ['name' => $source->getName()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_rag_sources');
    }

    // ─── Test recherche ─────────────────────────────────────────────────────

    #[Route('/{id}/recherche', name: 'rag_sources_search', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function search(SynapseRagSource $source, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_rag_source_search_'.$source->getId());

        $query = trim((string) $request->request->get('query', ''));
        $results = [];
        $error = null;

        if ('' !== $query) {
            try {
                $results = $this->ragManager->search($query, [$source->getSlug()], 10, 0.0);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('@Synapse/admin/memoire/rag_source_edit.html.twig', [
            'source' => $source,
            'is_new' => false,
            'has_provider' => $this->registry->has($source->getSlug()),
            'search_query' => $query,
            'search_results' => $results,
            'search_error' => $error,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function applyFormData(SynapseRagSource $source, array $data): void
    {
        $slugVal = $data['slug'] ?? null;
        if (is_string($slugVal)) {
            $source->setSlug(trim($slugVal));
        }

        $nameVal = $data['name'] ?? null;
        if (is_string($nameVal)) {
            $source->setName(trim($nameVal));
        }

        $descVal = $data['description'] ?? null;
        if (is_string($descVal)) {
            $source->setDescription(trim($descVal) ?: null);
        }

        $source->setIsActive(isset($data['is_active']));
    }
}
