<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\SynapseRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Spreading activation — propagation déterministe (sans LLM) du score depuis
 * un ensemble de seeds à travers le graphe des synapses.
 *
 * **Spec design** (cf. {@link docs/brain-v3-design.md} §10) :
 * > 1. Extraction prompt → neurones candidats
 * > 2. Pour chacun : récupérer voisins via synapses (poids > seuil), N sauts
 * > 3. Filtrer par functional network actif **(hors-scope jalon 4)**
 * > 4. Filtrer par aire selon type de question **(hors-scope jalon 4)**
 * > 5. Score combiné → top N
 * > 6. Injection contexte LLM
 *
 * **Implémentation BFS** (cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.3,
 * ADR-007 amendé, ADR-008 amendé) :
 *
 * Formule de score (ADR-008 amendée 2026-05-13 après revue de littérature
 * étendue : papers académiques + frameworks OSS + fondations Hebbien) :
 *
 * ```
 * propagated(depth)  = parentFinalScore × weight × confidence × DEPTH_DECAY^depth × recency
 * recency_factor     = RECENCY_DECAY^daysSinceActivation
 * hub_factor(degree) = 1 / (1 + HUB_PENALTY_ALPHA · (degree-1)²)   // formule mem0
 * final(neuron)      = arrivingScore × hub_factor(degree_of_neuron)
 * ```
 *
 * - **DEPTH_DECAY = 0.7** : zone empirique classique (Collins & Loftus 1975,
 *   ACT-R ~0.5). Décroissance spatiale par saut.
 * - **RECENCY_DECAY = 0.995** par jour : aligné Generative Agents (Park 2023)
 *   et HeLa-Mem (Zhu 2026). **À valider empiriquement** : aucun framework OSS
 *   (mem0, Letta, Cognee, HippoRAG, LightRAG) ne fait de decay temporel synapse.
 * - **HUB_PENALTY_ALPHA = 0.001** : pénalité hub formule mem0 (CatRAG 2026
 *   "Static Graph Fallacy"). degree=50 → 0.29, degree=100 → 0.09.
 * - **Critère d'arrêt principal** (ADR-007 amendé) : score < `query.minScore`
 *   → on n'inclut pas le neurone ET on ne propage pas plus loin
 * - **Hard cap profondeur** : `HARD_MAX_DEPTH` = 3 (convergence cross-source
 *   EcphoryRAG/SA-RAG/SCG-MEM/MemNN : gain nul ou négatif au-delà de 3 hops)
 *
 * **Anti-hub double protection** :
 * 1. `MAX_FANOUT_PER_HOP = 20` : plafonne le nombre de synapses sortantes
 *    explorées (tri par `weight × confidence` décroissant)
 * 2. `hubFactor` au scoring : pénalité quadratique douce sur le degré
 *    (sinon le fan-out cap ne dit pas au scoring qu'un hub est moins informatif)
 *
 * **Pas de polarité au jalon 4** :
 * - HeLa-Mem, HippoRAG 2, Generative Agents, A-MEM, SSGM : aucun ne valide
 *   la propagation d'inhibition sur plusieurs sauts.
 * - Risque d'amplification négative non-sensée à 3+ hops avec inversion de signe.
 * - Reporté à jalon 5+ avec validation empirique dédiée. Cf. ADR-008 amendé.
 *
 * **Isolation user** (ADR-006) : si `query.ownerId` non-null, on n'admet que
 * les synapses dont chaque côté est `null` (open) ou égal au `ownerId`.
 * Sinon (query open) → on n'admet que les synapses 100% open.
 *
 * **Pourquoi BFS et pas DFS** : on veut explorer les voisins les plus proches
 * (depth 1) avant d'aller loin, parce que le decay rend les branches lointaines
 * déjà très atténuées. BFS minimise le risque de tomber dans une branche
 * profonde de faible score sans avoir vu une branche courte de haut score.
 *
 * **Non garanti** : ce n'est pas Dijkstra. Si plusieurs chemins mènent au
 * même neurone, on garde le **premier visité** (typiquement le plus court).
 * Pas forcément celui de score max. Au jalon 5+, remplacement possible par
 * une vraie traversée pondérée si la qualité l'exige.
 */
final readonly class SpreadingActivation implements SpreadingActivationInterface
{
    /**
     * Hard cap profondeur. Réduit de 5 à 3 après revue de littérature
     * 2024-2026 (convergence EcphoryRAG, SA-RAG, SCG-MEM, MemNN classique :
     * gain négligeable ou négatif au-delà de 3 hops). Au cas où, reste
     * sécurité dure ; le critère réel d'arrêt est `query.minScore`.
     */
    public const HARD_MAX_DEPTH = 3;

    public const DEPTH_DECAY = 0.7;

    /**
     * Decay temporel par jour depuis dernière activation. 0.995^30 ≈ 0.86,
     * 0.995^90 ≈ 0.64, 0.995^365 ≈ 0.16.
     *
     * **À valider empiriquement** : aucun framework OSS ne fait de decay
     * temporel synapse. Aligné Generative Agents (Park 2023) et HeLa-Mem
     * (Zhu 2026) — mais ces decays sont sur l'activation, pas sur l'edge.
     * Si bench montre que c'est délétère, retirer.
     */
    public const RECENCY_DECAY = 0.995;

    /**
     * Plafond du nombre de synapses sortantes explorées par hop, pour éviter
     * qu'un neurone hub absorbe tout le BFS. Tri par `weight × confidence`
     * décroissant avant troncature.
     */
    public const MAX_FANOUT_PER_HOP = 20;

    /**
     * Coefficient de la pénalité hub (formule mem0).
     *
     * `hubFactor(degree) = 1 / (1 + α · (degree - 1)²)` avec α = `HUB_PENALTY_ALPHA`.
     *
     * À α=0.001 : degree=10 → 0.92, degree=50 → 0.29, degree=100 → 0.09.
     * Pénalité douce pour les degrés modestes, agressive pour les vrais hubs.
     */
    public const HUB_PENALTY_ALPHA = 0.001;

    private ClockInterface $clock;

    public function __construct(
        private SynapseRepository $synapseRepository,
        private NeuronResolverInterface $neuronResolver,
        private LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * @param list<ScoredNeuron> $seeds
     *
     * @return list<ScoredNeuron> trié par score décroissant, tronqué à `query.topN`
     */
    public function spread(array $seeds, RetrievalQuery $query): array
    {
        if ([] === $seeds) {
            return [];
        }

        $effectiveMaxDepth = min($query->maxDepth, self::HARD_MAX_DEPTH);

        /** @var array<string, ScoredNeuron> $visited */
        $visited = [];

        /** @var list<array{neuron: MemoryFragment, depth: int, score: float, reachedVia: ?Uuid}> $queue */
        $queue = [];

        foreach ($seeds as $seed) {
            $queue[] = [
                'neuron' => $seed->neuron,
                'depth' => 0,
                'score' => $seed->score,
                'reachedVia' => null,
            ];
        }

        while ([] !== $queue) {
            /** @var array{neuron: MemoryFragment, depth: int, score: float, reachedVia: ?Uuid} $node */
            $node = array_shift($queue);
            $current = $node['neuron'];
            $depth = $node['depth'];
            $arrivingScore = $node['score'];
            $reachedVia = $node['reachedVia'];

            $neuronKey = $current->getArea()->value.':'.$current->getId()->toRfc4122();

            if (isset($visited[$neuronKey])) {
                continue;
            }

            // Récupère outgoing UNE fois — sert au degree (hub factor) ET à la propagation
            $outgoing = $this->synapseRepository->findOutgoing($current);
            $outDegree = count($outgoing);
            $hubFactor = $this->hubFactor($outDegree);

            // Score final du neurone (avec pénalité hub appliquée)
            $finalScore = $arrivingScore * $hubFactor;

            if ($finalScore < $query->minScore) {
                continue;
            }

            $visited[$neuronKey] = new ScoredNeuron(
                neuron: $current,
                score: $finalScore,
                depth: $depth,
                reachedVia: $reachedVia,
            );

            if ($depth >= $effectiveMaxDepth) {
                continue;
            }

            $capped = $this->capByFanout($outgoing);

            foreach ($capped as $synapse) {
                if (!$this->isAdmissibleForOwner($synapse, $query->ownerId)) {
                    continue;
                }

                $targetNeuron = $this->neuronResolver->resolve(
                    $synapse->getTargetNeuronArea(),
                    $synapse->getTargetNeuronId(),
                );
                if (null === $targetNeuron) {
                    $this->logger->debug('SpreadingActivation: target neuron not resolvable, skipping', [
                        'synapseId' => $synapse->getId()->toRfc4122(),
                        'targetArea' => $synapse->getTargetNeuronArea()->value,
                        'targetId' => $synapse->getTargetNeuronId()->toRfc4122(),
                    ]);
                    continue;
                }

                // Propage le finalScore (hub-pénalisé) vers les descendants :
                // un hub bruité contamine moins, mais ne contamine pas non plus
                // les chemins valides qui passent par lui — c'est le compromis.
                $propagated = $this->propagatedScore($finalScore, $synapse, $depth + 1);

                if ($propagated < $query->minScore) {
                    continue;
                }

                $queue[] = [
                    'neuron' => $targetNeuron,
                    'depth' => $depth + 1,
                    'score' => $propagated,
                    'reachedVia' => $synapse->getId(),
                ];
            }
        }

        $result = array_values($visited);
        usort($result, static fn (ScoredNeuron $a, ScoredNeuron $b): int => $b->score <=> $a->score);

        return array_slice($result, 0, $query->topN);
    }

    /**
     * Tri par `weight × confidence` décroissant + troncature à `MAX_FANOUT_PER_HOP`.
     *
     * @param list<Synapse> $outgoing
     *
     * @return list<Synapse>
     */
    private function capByFanout(array $outgoing): array
    {
        if (count($outgoing) <= self::MAX_FANOUT_PER_HOP) {
            return $outgoing;
        }

        usort($outgoing, static fn (Synapse $a, Synapse $b): int => ($b->getWeight() * $b->getConfidence()) <=> ($a->getWeight() * $a->getConfidence()));

        return array_slice($outgoing, 0, self::MAX_FANOUT_PER_HOP);
    }

    /**
     * Score qui voyage le long d'une synapse vers un descendant.
     *
     * `propagated = parentFinalScore × weight × confidence × DEPTH_DECAY^depth × recency`
     *
     * Le hub factor du *target* sera appliqué quand le target sera dequeue
     * (pas ici — sinon double comptage).
     *
     * Polarity ignorée au jalon 4 (cf. ADR-008 amendé). Re-introduction
     * éventuelle au jalon 5+ avec validation empirique.
     */
    private function propagatedScore(float $parentFinalScore, Synapse $synapse, int $newDepth): float
    {
        $pathDecay = $synapse->getWeight() * $synapse->getConfidence() * (self::DEPTH_DECAY ** $newDepth);
        $recency = $this->recencyFactor($synapse);

        return $parentFinalScore * $pathDecay * $recency;
    }

    /**
     * `hubFactor = 1 / (1 + α·(degree - 1)²)` — formule mem0 (`memory_count_weight`).
     *
     * Pénalité quadratique douce : un neurone très connecté est moins
     * informatif (concept générique, "hub"), donc pénalisé au scoring.
     * Combat directement la "Static Graph Fallacy" (CatRAG 2026).
     *
     * Exemple α=0.001 :
     * - degree=1 → 1.0 (pas de pénalité)
     * - degree=10 → 0.92
     * - degree=50 → 0.29
     * - degree=100 → 0.09
     */
    private function hubFactor(int $outDegree): float
    {
        if ($outDegree <= 1) {
            return 1.0;
        }
        $d = $outDegree - 1;

        return 1.0 / (1.0 + (self::HUB_PENALTY_ALPHA * $d * $d));
    }

    /**
     * `RECENCY_DECAY^daysSinceActivation`. Borné à 1.0 (synapse activée dans
     * le futur — incohérence horloge — n'apporte pas de bonus).
     */
    private function recencyFactor(Synapse $synapse): float
    {
        $now = $this->clock->now();
        $diffSeconds = $now->getTimestamp() - $synapse->getLastActivatedAt()->getTimestamp();
        if ($diffSeconds <= 0) {
            return 1.0;
        }
        $days = $diffSeconds / 86400.0;

        return self::RECENCY_DECAY ** $days;
    }

    /**
     * - query.ownerId = null → admet uniquement synapses 100% open
     * - query.ownerId = Alice → admet synapses dont chaque côté est null ou Alice.
     */
    private function isAdmissibleForOwner(Synapse $synapse, ?Uuid $queryOwner): bool
    {
        $src = $synapse->getSourceNeuronOwner();
        $tgt = $synapse->getTargetNeuronOwner();

        if (null === $queryOwner) {
            return null === $src && null === $tgt;
        }

        $srcOk = null === $src || $src->equals($queryOwner);
        $tgtOk = null === $tgt || $tgt->equals($queryOwner);

        return $srcOk && $tgtOk;
    }
}
