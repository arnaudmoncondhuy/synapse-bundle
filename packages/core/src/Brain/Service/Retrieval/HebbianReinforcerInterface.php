<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;

/**
 * Renforcement Hebbien d'une synapse (saturation soft).
 *
 * Existe pour permettre le mocking en test ({@see HebbianReinforcer} est
 * `final readonly` et non doublable par PHPUnit).
 */
interface HebbianReinforcerInterface
{
    /**
     * Renforce une synapse — `w = w + δ × (1 - w)` (saturation soft, cf. ADR-009).
     */
    public function reinforce(Synapse $synapse, string $cause = 'hebbian_co_activation'): void;

    /**
     * @param iterable<Synapse> $synapses
     */
    public function reinforceAll(iterable $synapses, string $cause = 'hebbian_co_activation'): void;
}
