<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\VectorStore;

use ArnaudMoncondhuy\SynapseBundle\Contract\VectorStoreInterface;

/**
 * Implémentation par défaut qui ne fait rien (Null Pattern).
 * Permet au bundle de fonctionner sans configuration de stockage vectoriel.
 */
class NullVectorStore implements VectorStoreInterface
{
    public function saveMemory(array $vector, array $payload): void
    {
        // Ne fait rien
    }

    public function searchSimilar(array $vector, int $limit = 5, array $filters = []): array
    {
        return [];
    }
}
