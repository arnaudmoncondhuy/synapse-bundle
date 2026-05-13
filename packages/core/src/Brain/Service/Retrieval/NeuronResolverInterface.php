<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use Symfony\Component\Uid\Uuid;

/**
 * Résout un neurone polymorphe depuis le couple (aire, uuid).
 *
 * Existe principalement pour permettre le mocking en test (`NeuronResolver`
 * est `final readonly` et donc non-doublable par PHPUnit). L'implémentation
 * de production est {@see NeuronResolver}.
 */
interface NeuronResolverInterface
{
    public function resolve(BrainArea $area, Uuid $neuronId): ?MemoryFragment;
}
