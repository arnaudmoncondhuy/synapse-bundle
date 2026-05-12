<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\EmbeddableNeuron;
use ArnaudMoncondhuy\SynapseCore\Brain\Exception\SynapseUserIsolationViolationException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use Symfony\Component\Uid\Uuid;

/**
 * Détecte les convergences mémorielles entre un neurone fraîchement extrait
 * et les neurones existants de la même aire, en se basant sur la similarité
 * cosine de leurs embeddings.
 *
 * Pour chaque convergence détectée (similarité ≥ seuil), crée une `Synapse`
 * de type `CORROBORATES` entre le candidat et le neurone existant.
 *
 * **Isolation user** (ADR-006) : les neurones existants doivent être
 * filtrés par owner_id en amont (par le caller qui requête le repository).
 * Si on tente de lier 2 neurones d'owners différents non-null,
 * `Synapse::__construct` throw — on intercepte et on skippe silencieusement
 * (ne devrait pas arriver si le caller fait son travail).
 *
 * **Pas de fusion** : ce détecteur crée des synapses, pas des merges. La
 * fusion (corroborate() côté SemanticNeuron) sera la responsabilité du
 * service de consolidation au jalon 8.
 *
 * **Pas de persistance** : retourne les synapses créées en mémoire, c'est
 * au caller (MemoryExtractor ou un service supérieur) de les flusher.
 *
 * Cf. {@link docs/brain/06-phases/jalon-3-ingestion-multi-aires.md} §4.4
 * et {@link docs/brain/05-decisions/005-seuil-cosine-convergence.md} (ADR-005).
 */
final readonly class ConvergenceDetector
{
    /**
     * Seuil cosine par défaut.
     *
     * **Placeholder** (ADR-005) : à calibrer empiriquement à l'étape 10
     * du jalon 3 sur fixtures annotées. Valeur 0.85 raisonnable a priori
     * mais non validée.
     */
    public const DEFAULT_COSINE_THRESHOLD = 0.85;

    /**
     * Détecte les convergences entre le candidat et les neurones existants
     * de la même aire fournis par le caller, et crée les synapses
     * CORROBORATES correspondantes.
     *
     * @param EmbeddableNeuron $candidate neurone fraîchement
     *                                    extrait, pas encore
     *                                    persisté
     * @param ?Uuid $candidateOwner ownerId du candidat
     *                              (NULL si source open)
     * @param list<ConvergenceCandidate> $existing neurones existants
     *                                             de la même aire
     *                                             déjà filtrés par
     *                                             compatibilité owner
     * @param float $threshold seuil cosine
     *                         (défaut: 0.85)
     *
     * @return list<Synapse> synapses CORROBORATES créées (non persistées)
     */
    public function detectAndLink(
        EmbeddableNeuron $candidate,
        ?Uuid $candidateOwner,
        array $existing,
        float $threshold = self::DEFAULT_COSINE_THRESHOLD,
    ): array {
        $candidateEmbedding = $candidate->getEmbedding();
        if ([] === $candidateEmbedding) {
            return [];
        }

        $synapses = [];

        foreach ($existing as $candidateExisting) {
            $existingNeuron = $candidateExisting->neuron;

            // Sanity check : même aire (responsabilité du caller, mais on
            // protège quand même)
            if ($existingNeuron->getArea() !== $candidate->getArea()) {
                continue;
            }

            // Pas de self-lien : si le caller a inclus le candidat lui-même
            // dans la liste existing par erreur, on skip
            if ($existingNeuron->getId()->equals($candidate->getId())) {
                continue;
            }

            $similarity = CosineSimilarity::compute(
                $candidateEmbedding,
                $existingNeuron->getEmbedding(),
            );

            if ($similarity < $threshold) {
                continue;
            }

            // Tentative de création de synapse — peut throw isolation user
            // si le caller n'a pas filtré correctement
            try {
                $synapses[] = new Synapse(
                    source: $candidate,
                    target: $existingNeuron,
                    sourceOwnerId: $candidateOwner,
                    targetOwnerId: $candidateExisting->ownerId,
                    weight: $similarity, // poids initial = similarité cosine
                    relationType: SynapseRelationType::Corroborates,
                    confidence: $similarity,
                    evidenceCount: 1,
                );
            } catch (SynapseUserIsolationViolationException) {
                // Le caller n'a pas filtré — skip silencieux et continue
                // (le garde-fou Synapse::__construct a fait son travail)
                continue;
            }
        }

        return $synapses;
    }
}
