<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use Symfony\Component\Uid\Uuid;

/**
 * Un neurone retourné par le retrieval, avec son score et son contexte
 * d'activation.
 *
 * Immutable. Sert à exposer le détail d'activation pour audit
 * (debug, UI graphe, raisonnement typé côté LLM).
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.1.
 */
final readonly class ScoredNeuron
{
    /**
     * @param MemoryFragment $neuron le neurone retrouvé
     * @param float $score score combiné (0-1, plus haut = plus pertinent)
     * @param int $depth distance depuis le seed (0 = seed lui-même)
     * @param ?Uuid $reachedVia UUID de la synapse traversée pour atteindre ce neurone (null pour les seeds)
     */
    public function __construct(
        public MemoryFragment $neuron,
        public float $score,
        public int $depth,
        public ?Uuid $reachedVia = null,
    ) {
    }
}
