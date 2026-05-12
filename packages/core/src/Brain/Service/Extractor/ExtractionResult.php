<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Résultat d'une extraction de neurones depuis une source.
 *
 * Immutable. Contient les neurones produits par l'extraction (peut être vide
 * si la source ne contient rien de pertinent pour l'aire visée — sélectivité
 * naturelle, design §38).
 */
final readonly class ExtractionResult
{
    /**
     * @param list<MemoryFragment> $neurons Neurones extraits (peut être vide)
     * @param array<string, mixed> $debug Trace pour audit / debug
     *                                    (ex: model utilisé, prompt version,
     *                                    tokens consommés, latence)
     */
    public function __construct(
        public BrainArea $area,
        public array $neurons,
        public array $debug = [],
    ) {
    }

    public function count(): int
    {
        return count($this->neurons);
    }

    public function isEmpty(): bool
    {
        return [] === $this->neurons;
    }

    public static function empty(BrainArea $area): self
    {
        return new self($area, [], []);
    }
}
