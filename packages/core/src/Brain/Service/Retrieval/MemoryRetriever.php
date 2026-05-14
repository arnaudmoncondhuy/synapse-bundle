<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\EmbeddableNeuron;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\CosineSimilarity;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\SynapseRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrateur du retrieval Brain (jalon 4).
 *
 * Pipeline :
 * 1. {@see SeedExtractor} génère les seeds par similarité vectorielle
 * 2. {@see SpreadingActivation} propage le score sur le graphe Hebbien
 * 3. **Re-rank final** : multiplie le score BFS par la similarité cosine
 *    entre l'embedding du neurone et celui de la query (insight EcphoryRAG
 *    arxiv 2510.08958 — anti-drift sémantique lié au spreading multi-hop)
 * 4. **Hebbien implicite** (mode A design §10) : on renforce les synapses
 *    *effectivement traversées* (via `reachedVia`). Pas toutes les paires
 *    O(N²) — plus juste et plus rapide. Pas de création de synapses au
 *    retrieval (cf. plan §4.5).
 * 5. Construction du `RetrievalResult` avec debug détaillé pour audit
 *    (phase bench du jalon 4).
 *
 * Coût attendu : 2 appels embedding (seeds + re-rank) + N appels SQL BFS.
 * Si le bench montre que c'est trop lent, on mémoïse l'embedding query
 * dans un wrapper `SeedExtractionResult`. Pour l'instant, simple > performant.
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.4.
 */
final readonly class MemoryRetriever implements MemoryRetrieverInterface
{
    /**
     * Coefficient du score path vs cosine d'arrivée dans le re-rank final :
     *
     * ```
     * final = RERANK_PATH_WEIGHT × BFS + (1 - RERANK_PATH_WEIGHT) × cosine_query
     * ```
     *
     * `0.6` privilégie légèrement le BFS (structurel) tout en laissant 40%
     * de poids au cosine d'arrivée (sémantique). À calibrer empiriquement
     * sur le corpus de test (étape 11 bench, cf. ADR-010).
     */
    public const RERANK_PATH_WEIGHT = 0.6;

    public function __construct(
        private SeedExtractorInterface $seedExtractor,
        private SpreadingActivationInterface $spreadingActivation,
        private HebbianReinforcerInterface $hebbianReinforcer,
        private SynapseRepository $synapseRepository,
        private EmbeddingService $embeddingService,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function retrieve(RetrievalQuery $query): RetrievalResult
    {
        $startedAt = microtime(true);

        $seeds = $this->seedExtractor->extract($query);
        $seedsAt = microtime(true);

        if ([] === $seeds) {
            return new RetrievalResult([], [
                'seeds_count' => 0,
                'reason' => 'no_seeds_matched',
                'timing_ms' => (int) round(($seedsAt - $startedAt) * 1000),
            ]);
        }

        $spread = $this->spreadingActivation->spread($seeds, $query);
        $spreadAt = microtime(true);

        if ([] === $spread) {
            return new RetrievalResult([], [
                'seeds_count' => count($seeds),
                'spread_count' => 0,
                'reason' => 'spread_below_min_score',
                'timing_ms' => (int) round(($spreadAt - $startedAt) * 1000),
            ]);
        }

        $queryEmbedding = $this->fetchQueryEmbedding($query->text);
        $reranked = $this->rerankAgainstQuery($spread, $queryEmbedding);
        $rerankedAt = microtime(true);

        $reinforcedSynapses = $this->reinforceTraversedSynapses($reranked);
        $reinforcedAt = microtime(true);

        return new RetrievalResult($reranked, [
            'seeds_count' => count($seeds),
            'seed_top_score' => $seeds[0]->score,
            'spread_count' => count($spread),
            'reranked_count' => count($reranked),
            'reinforced_synapses_count' => $reinforcedSynapses,
            'query_embedded' => [] !== $queryEmbedding,
            'timing_ms' => [
                'total' => (int) round(($reinforcedAt - $startedAt) * 1000),
                'seeds' => (int) round(($seedsAt - $startedAt) * 1000),
                'spread' => (int) round(($spreadAt - $seedsAt) * 1000),
                'rerank' => (int) round(($rerankedAt - $spreadAt) * 1000),
                'reinforce' => (int) round(($reinforcedAt - $rerankedAt) * 1000),
            ],
        ]);
    }

    /**
     * @return list<float>
     */
    private function fetchQueryEmbedding(string $text): array
    {
        try {
            /** @var array{embeddings: list<list<float>>, usage: array<string, int>} $result */
            $result = $this->embeddingService->generateEmbeddings($text, null, 'brain_retrieval');

            return $result['embeddings'][0] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('MemoryRetriever: query embedding failed, skipping rerank', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Re-rank EcphoryRAG : `final = α × BFS + (1-α) × cosine(neuron, query)`.
     *
     * Neurones sans embedding (procédural, …) ou query sans embedding (échec
     * EmbeddingService) → on garde le score BFS tel quel.
     *
     * @param list<ScoredNeuron> $spread
     * @param list<float> $queryEmbedding
     *
     * @return list<ScoredNeuron> ré-triés par nouveau score décroissant
     */
    private function rerankAgainstQuery(array $spread, array $queryEmbedding): array
    {
        if ([] === $queryEmbedding) {
            return $spread;
        }

        $reranked = [];
        foreach ($spread as $scored) {
            $neuron = $scored->neuron;
            if (!$neuron instanceof EmbeddableNeuron) {
                $reranked[] = $scored;
                continue;
            }
            $emb = $neuron->getEmbedding();
            if ([] === $emb) {
                $reranked[] = $scored;
                continue;
            }
            $cos = CosineSimilarity::compute($queryEmbedding, $emb);
            // Cosine peut être négatif théoriquement ; on clamp à 0 (un neurone
            // "anti-corrélé" à la query n'a pas à booster son score)
            $cos = max(0.0, $cos);

            $newScore = (self::RERANK_PATH_WEIGHT * $scored->score) + ((1.0 - self::RERANK_PATH_WEIGHT) * $cos);

            $reranked[] = new ScoredNeuron(
                neuron: $scored->neuron,
                score: $newScore,
                depth: $scored->depth,
                reachedVia: $scored->reachedVia,
            );
        }

        usort($reranked, static fn (ScoredNeuron $a, ScoredNeuron $b): int => $b->score <=> $a->score);

        return $reranked;
    }

    /**
     * Hebbien implicite (mode A §10) : renforce les synapses effectivement
     * traversées dans le retrieval. Pas de création de synapses ici.
     *
     * @param list<ScoredNeuron> $neurons
     *
     * @return int nombre de synapses renforcées
     */
    private function reinforceTraversedSynapses(array $neurons): int
    {
        $count = 0;
        foreach ($neurons as $scored) {
            if (null === $scored->reachedVia) {
                continue;
            }
            $synapse = $this->synapseRepository->find($scored->reachedVia);
            if (null === $synapse) {
                continue;
            }
            $this->hebbianReinforcer->reinforce($synapse, 'retrieval_co_activation');
            ++$count;
        }

        return $count;
    }
}
