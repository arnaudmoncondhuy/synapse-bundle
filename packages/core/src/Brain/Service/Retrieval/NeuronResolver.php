<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EncyclopedicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EpisodicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\ProceduralNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\SemanticNeuronRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Résout un neurone polymorphe depuis le couple (aire, uuid).
 *
 * Le `SpreadingActivation` parcourt le graphe via `Synapse` qui stocke
 * `(targetNeuronArea, targetNeuronId)` — il a besoin de récupérer le
 * neurone concret pour calculer la similarité, le score, et l'inclure
 * dans le résultat.
 *
 * Dispatch par aire vers le bon repository. Les aires non-embeddables
 * (Procedural pour l'instant) sont résolues quand même — elles peuvent
 * apparaître dans le graphe via synapses mais ne participent pas au
 * cosine.
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.6.
 */
final readonly class NeuronResolver
{
    public function __construct(
        private SemanticNeuronRepository $semanticRepo,
        private EpisodicNeuronRepository $episodicRepo,
        private EncyclopedicNeuronRepository $encyclopedicRepo,
        private ProceduralNeuronRepository $proceduralRepo,
    ) {
    }

    public function resolve(BrainArea $area, Uuid $neuronId): ?MemoryFragment
    {
        $neuron = match ($area) {
            BrainArea::Semantic => $this->semanticRepo->find($neuronId),
            BrainArea::Episodic => $this->episodicRepo->find($neuronId),
            BrainArea::Encyclopedic => $this->encyclopedicRepo->find($neuronId),
            BrainArea::Procedural => $this->proceduralRepo->find($neuronId),
            // Aires non encore activées au jalon 4
            BrainArea::Emotional, BrainArea::Sensory, BrainArea::Motor => null,
        };

        if (null === $neuron) {
            return null;
        }
        if (!$neuron instanceof MemoryFragment) {
            // Cas impossible si les repositories sont bien typés, mais on garde
            // la garantie contractuelle au cas où
            return null;
        }

        return $neuron;
    }
}
