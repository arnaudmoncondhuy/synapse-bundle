<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Memoire;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Core\VectorStore\VectorStoreRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Embeddings & RAG — Configuration du moteur sémantique — Administration Synapse
 */
#[Route('/synapse/admin/memoire/embeddings', name: 'synapse_admin_')]
class EmbeddingController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $modelRegistry,
        private EmbeddingService $embeddingService,
        private VectorStoreRegistry $vectorStoreRegistry,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    #[Route('', name: 'embeddings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();
        $activeProviders = array_filter(
            $this->providerRepo->findAllOrdered(),
            fn($p) => $p->isEnabled(),
        );

        // Modèles d'embedding disponibles par fournisseur
        $embeddingModelsByProvider = [];
        foreach ($activeProviders as $provider) {
            $name = $provider->getName();
            $models = [];
            foreach ($this->modelRegistry->getModelsForProvider($name) as $modelId) {
                $caps = $this->modelRegistry->getCapabilities($modelId);
                if ($caps->type === 'embedding') {
                    $models[$modelId] = $caps;
                }
            }
            $embeddingModelsByProvider[$name] = $models;
        }

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_embeddings');
            $data = $request->request->all();

            $embProvider  = !empty($data['embedding_provider'])  ? $data['embedding_provider']  : null;
            $embModel     = !empty($data['embedding_model'])     ? $data['embedding_model']     : null;
            $embDimension = !empty($data['embedding_dimension']) ? (int) $data['embedding_dimension'] : null;

            // Validation : le modèle doit appartenir au provider
            if ($embProvider && $embModel && !isset($embeddingModelsByProvider[$embProvider][$embModel])) {
                $this->addFlash('error', 'Le modèle sélectionné n\'appartient pas au fournisseur choisi.');
                return $this->redirectToRoute('synapse_admin_embeddings');
            }

            $chunkSize     = max(100, min(20000, (int) ($data['chunk_size'] ?? 1000)));
            $chunkOverlap  = max(0, min($chunkSize - 50, (int) ($data['chunk_overlap'] ?? 200)));
            $vectorStore   = !empty($data['vector_store']) ? $data['vector_store'] : 'doctrine';
            $chunkStrategy = !empty($data['chunking_strategy']) ? $data['chunking_strategy'] : 'recursive';

            $config->setEmbeddingProvider($embProvider)
                ->setEmbeddingModel($embModel)
                ->setEmbeddingDimension($embDimension)
                ->setChunkingStrategy($chunkStrategy)
                ->setChunkSize($chunkSize)
                ->setChunkOverlap($chunkOverlap)
                ->setVectorStore($vectorStore);

            $this->em->flush();
            $this->addFlash('success', 'Configuration des Embeddings mise à jour.');

            return $this->redirectToRoute('synapse_admin_embeddings');
        }

        // Détection pgvector
        $connection = $this->em->getConnection();
        $platform   = $connection->getDatabasePlatform()::class;
        $isPostgres = str_contains($platform, 'PostgreSQL');
        $vectorExtensionEnabled = false;
        if ($isPostgres) {
            try {
                $ext = $connection->executeQuery("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetchOne();
                $vectorExtensionEnabled = (bool) $ext;
            } catch (\Exception) {
                $vectorExtensionEnabled = false;
            }
        }

        return $this->render('@Synapse/admin/memoire/embeddings.html.twig', [
            'config'                      => $config,
            'active_providers'            => $activeProviders,
            'embedding_models_by_provider' => $embeddingModelsByProvider,
            'db_is_postgres'              => $isPostgres,
            'db_vector_ready'             => $vectorExtensionEnabled,
            'available_vector_stores'     => $this->vectorStoreRegistry->getAvailableAliases(),
        ]);
    }

    #[Route('/test', name: 'embeddings_test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_embeddings_test');

        try {
            $text      = $request->request->get('text', 'Test d\'embedding Synapse.');
            $start     = microtime(true);
            $result    = $this->embeddingService->generateEmbeddings($text);
            $elapsed   = round((microtime(true) - $start) * 1000);
            $dimension = isset($result['embeddings'][0]) ? count($result['embeddings'][0]) : 0;

            return $this->json([
                'success'   => true,
                'dimension' => $dimension,
                'time_ms'   => $elapsed,
                'usage'     => $result['usage'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
