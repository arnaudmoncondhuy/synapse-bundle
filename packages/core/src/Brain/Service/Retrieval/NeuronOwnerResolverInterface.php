<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use Symfony\Component\Uid\Uuid;

/**
 * Résout l'ownerId d'un neurone (via sa MemorySource) pour filtrer
 * seeds et propagation BFS — défense en profondeur ADR-006.
 *
 * Existe pour permettre le mocking ({@see NeuronOwnerResolver} a un cache
 * local et n'est pas final readonly pour la même raison).
 */
interface NeuronOwnerResolverInterface
{
    public function resolve(MemoryFragment $neuron): ?Uuid;

    public function isAdmissibleForOwner(?Uuid $neuronOwner, ?Uuid $queryOwner): bool;
}
