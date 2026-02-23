<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/synapse/admin/embeddings')]
class EmbeddingSettingsController extends AbstractController
{
    public function __construct(
        private SynapseConfigRepository $configRepo,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $modelRegistry,
        private EntityManagerInterface $em,
        private \ArnaudMoncondhuy\SynapseBundle\Core\Service\EmbeddingService $embeddingService,
    ) {}

    #[Route('', name: 'synapse_admin_embeddings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $config = $this->configRepo->getGlobalConfig();
        $providers = $this->providerRepo->findAllOrdered();

        // On ne propose que les fournisseurs activés
        $activeProviders = array_filter($providers, fn($p) => $p->isEnabled());

        // Récupérer la liste complète des modèles d'embedding par fournisseur (pour l'interface UI)
        $embeddingModelsByProvider = [];
        foreach ($activeProviders as $provider) {
            $providerName = $provider->getName();
            $models = [];
            foreach ($this->modelRegistry->getModelsForProvider($providerName) as $modelId) {
                $caps = $this->modelRegistry->getCapabilities($modelId);
                if ($caps->type === 'embedding') {
                    $models[$modelId] = $caps;
                }
            }
            $embeddingModelsByProvider[$providerName] = $models;
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            $embeddingProvider = !empty($data['embedding_provider']) ? $data['embedding_provider'] : null;
            $embeddingModel = !empty($data['embedding_model']) ? $data['embedding_model'] : null;
            $embeddingDimension = !empty($data['embedding_dimension']) ? (int) $data['embedding_dimension'] : null;

            // Valider que le modèle appartient bien au fournisseur choisi
            if ($embeddingProvider && $embeddingModel && !isset($embeddingModelsByProvider[$embeddingProvider][$embeddingModel])) {
                $this->addFlash('error', 'Le modèle sélectionné n\'appartient pas au fournisseur choisi.');
                return $this->redirectToRoute('synapse_admin_embeddings');
            }

            $config->setEmbeddingProvider($embeddingProvider);
            $config->setEmbeddingModel($embeddingModel);
            $config->setEmbeddingDimension($embeddingDimension);

            $this->em->flush();
            $this->addFlash('success', 'Configuration des Embeddings mise à jour.');

            return $this->redirectToRoute('synapse_admin_embeddings');
        }

        // DB Status Check
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform()::class;
        $isPostgres = str_contains($platform, 'PostgreSQL');
        $vectorExtensionEnabled = false;

        if ($isPostgres) {
            try {
                $ext = $connection->executeQuery("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetchOne();
                $vectorExtensionEnabled = (bool) $ext;
            } catch (\Exception $e) {
                $vectorExtensionEnabled = false;
            }
        }

        return $this->render('@Synapse/admin/embeddings.html.twig', [
            'config' => $config,
            'active_providers' => $activeProviders,
            'embedding_models_by_provider' => $embeddingModelsByProvider,
            'db_is_postgres' => $isPostgres,
            'db_vector_ready' => $vectorExtensionEnabled,
            'db_platform' => (new \ReflectionClass($platform))->getShortName(),
        ]);
    }

    #[Route('/test', name: 'synapse_admin_embeddings_test', methods: ['POST'])]
    public function testEmbedding(Request $request): Response
    {
        try {
            $text = $request->request->get('text', 'Test text for embedding generation.');

            $startTime = microtime(true);
            $result = $this->embeddingService->generateEmbeddings($text);
            $endTime = microtime(true);

            $dimension = isset($result['embeddings'][0]) ? count($result['embeddings'][0]) : 0;

            return $this->json([
                'success' => true,
                'dimension' => $dimension,
                'time' => round(($endTime - $startTime) * 1000), // En millisecondes
                'usage' => $result['usage'] ?? null,
                'sample' => $dimension > 0 ? array_slice($result['embeddings'][0], 0, 100) : [],
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
