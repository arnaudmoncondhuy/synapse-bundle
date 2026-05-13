<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Event;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché après chaque renforcement d'une `Synapse` (ADR-009).
 *
 * Permet aux sécurités futures (anti-emballement avancé, compétition
 * latérale, audit Palantir, normalization batch) de se brancher comme
 * listeners sans toucher au `HebbianReinforcer`.
 *
 * Immutable. Porte la synapse mutée + valeur AVANT et APRÈS + cause.
 *
 * Cf. {@link docs/brain/05-decisions/009-saturation-soft-anti-emballement-synapses.md}
 * et `feedback-brain-event-driven-synapse-mutations` (mémoire utilisateur).
 */
final class SynapseReinforcedEvent extends Event
{
    public function __construct(
        public readonly Synapse $synapse,
        public readonly float $oldWeight,
        public readonly float $newWeight,
        public readonly string $cause,
    ) {
    }
}
