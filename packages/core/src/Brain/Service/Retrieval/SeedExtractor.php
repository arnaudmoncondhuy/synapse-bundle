<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\EmbeddableNeuron;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\CosineSimilarity;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EncyclopedicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EpisodicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\SemanticNeuronRepository;

/**
 * Produit les **seeds** d'un retrieval Hebbien — neurones initiaux candidats
 * par similarité vectorielle avec la requête.
 *
 * Algorithme :
 * 1. Génère l'embedding de la requête (`EmbeddingService`, purpose `brain_retrieval`)
 * 2. Charge tous les `EmbeddableNeuron` (Semantic + Episodic + Encyclopedic)
 * 3. Calcule cosine entre l'embedding requête et chaque neurone
 * 4. Filtre par seuil minimum (plus laxe que la convergence — on cherche
 *    juste à amorcer le retrieval)
 * 5. Trie par score, retourne top-K
 *
 * **Note perf** : à l'échelle, charger tous les neurones en mémoire est
 * inefficace. À migrer vers pgvector (opérateur `<=>`) au jalon 5+ quand
 * le volume le justifiera. Pour le jalon 4 (corpus <1000 neurones), c'est
 * acceptable.
 *
 * **Note isolation user** : le filtrage par owner n'est pas appliqué au
 * niveau seeds (pour l'instant). Le `SpreadingActivation` filtre via les
 * synapses qui portent `source_neuron_owner`/`target_neuron_owner`
 * (ADR-006). Au jalon 5+, on ajoutera un filtrage SQL côté repositories.
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.2.
 */
final readonly class SeedExtractor implements SeedExtractorInterface
{
    /**
     * Seuil cosine minimum pour qu'un neurone soit candidat seed.
     * Plus bas que le seuil de convergence (0.65, ADR-005) car on cherche
     * juste à amorcer — la propagation BFS fera le tri ensuite.
     */
    public const DEFAULT_SEED_THRESHOLD = 0.40;

    /**
     * Nombre max de seeds par retrieval. Limiter évite l'explosion BFS.
     */
    public const DEFAULT_MAX_SEEDS = 5;

    public function __construct(
        private EmbeddingService $embeddingService,
        private SemanticNeuronRepository $semanticRepo,
        private EpisodicNeuronRepository $episodicRepo,
        private EncyclopedicNeuronRepository $encyclopedicRepo,
        private float $seedThreshold = self::DEFAULT_SEED_THRESHOLD,
        private int $maxSeeds = self::DEFAULT_MAX_SEEDS,
    ) {
    }

    /**
     * @return list<ScoredNeuron> seeds triés par score décroissant
     */
    public function extract(RetrievalQuery $query): array
    {
        // 1. Embed la requête
        /** @var array{embeddings: list<list<float>>, usage: array{prompt_tokens: int, total_tokens: int}} $embeddingResult */
        $embeddingResult = $this->embeddingService->generateEmbeddings(
            $query->text,
            null,
            'brain_retrieval',
        );
        $queryEmbedding = $embeddingResult['embeddings'][0] ?? [];

        if ([] === $queryEmbedding) {
            return [];
        }

        // 2. Charge tous les neurones embeddables (3 aires actives au jalon 4)
        /** @var list<EmbeddableNeuron> $candidates */
        $candidates = [];
        /** @var list<EmbeddableNeuron> $semantic */
        $semantic = $this->semanticRepo->findAll();
        /** @var list<EmbeddableNeuron> $episodic */
        $episodic = $this->episodicRepo->findAll();
        /** @var list<EmbeddableNeuron> $encyclopedic */
        $encyclopedic = $this->encyclopedicRepo->findAll();
        $candidates = array_merge($candidates, $semantic, $episodic, $encyclopedic);

        if ([] === $candidates) {
            return [];
        }

        // 3. Calcule similarité, filtre par seuil
        $scored = [];
        foreach ($candidates as $neuron) {
            $embedding = $neuron->getEmbedding();
            if ([] === $embedding) {
                continue;
            }
            $score = CosineSimilarity::compute($queryEmbedding, $embedding);
            if ($score >= $this->seedThreshold) {
                $scored[] = new ScoredNeuron(
                    neuron: $neuron,
                    score: $score,
                    depth: 0,
                    reachedVia: null,
                );
            }
        }

        // 4. Tri par score décroissant + top-K
        usort($scored, static fn (ScoredNeuron $a, ScoredNeuron $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, $this->maxSeeds);
    }
}
