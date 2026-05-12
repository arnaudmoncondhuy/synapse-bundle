<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\EmbeddableNeuron;
use Symfony\Component\Uid\Uuid;

/**
 * Représente un neurone existant en BDD candidat à la convergence avec un
 * neurone fraîchement extrait, avec son propriétaire pré-résolu.
 *
 * Le `ownerId` est récupéré côté MemorySource en amont — l'injecter ici
 * évite à ConvergenceDetector de connaître MemorySourceRepository (ce qui
 * coupe le couplage et garde la responsabilité dans le caller).
 */
final readonly class ConvergenceCandidate
{
    public function __construct(
        public EmbeddableNeuron $neuron,
        public ?Uuid $ownerId,
    ) {
    }
}
