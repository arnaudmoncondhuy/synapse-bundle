<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

/**
 * Résultat d'un retrieval Brain.
 *
 * Immutable. Liste de neurones triés par score décroissant + trace debug
 * pour audit (seeds, sauts, timing, etc.).
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.1.
 */
final readonly class RetrievalResult
{
    /**
     * @param list<ScoredNeuron> $neurons triés par score décroissant
     * @param array<string, mixed> $debug contexte pour audit (seeds initiaux, traversal,
     *                                    timing, model embedding utilisé, etc.)
     */
    public function __construct(
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

    public static function empty(): self
    {
        return new self([], []);
    }
}
