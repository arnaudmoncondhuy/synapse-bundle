<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Exception;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use Symfony\Component\Uid\Uuid;

/**
 * Levée par `Synapse::__construct` quand on tente de lier deux neurones
 * appartenant à des utilisateurs différents (et tous deux non-null).
 *
 * Cf. {@link docs/brain/05-decisions/006-isolation-user-synapses.md} (ADR-006).
 * Règle d'admissibilité :
 *
 * | source owner | target owner | admissible |
 * |--------------|--------------|------------|
 * | user X       | user X       | ✅          |
 * | user X       | open (null)  | ✅          |
 * | open         | open         | ✅          |
 * | user X       | user Y (X≠Y) | ❌ → cette exception |
 *
 * Conséquence : protection contre les fuites cross-user (RGPD, sécurité)
 * appliquée à la création de chaque Synapse.
 */
final class SynapseUserIsolationViolationException extends \InvalidArgumentException
{
    public function __construct(
        public readonly BrainArea $sourceArea,
        public readonly Uuid $sourceNeuronId,
        public readonly Uuid $sourceOwnerId,
        public readonly BrainArea $targetArea,
        public readonly Uuid $targetNeuronId,
        public readonly Uuid $targetOwnerId,
    ) {
        parent::__construct(sprintf(
            'Synapse user isolation violation: cannot link %s neuron %s (owner=%s) to %s neuron %s (owner=%s) — different non-null owners.',
            $sourceArea->value,
            $sourceNeuronId->toRfc4122(),
            $sourceOwnerId->toRfc4122(),
            $targetArea->value,
            $targetNeuronId->toRfc4122(),
            $targetOwnerId->toRfc4122(),
        ));
    }
}
