<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

/**
 * Produit les seeds d'un retrieval Brain (neurones candidats initiaux).
 *
 * Existe pour permettre le mocking en test ({@see SeedExtractor} est
 * `final readonly` et non doublable par PHPUnit).
 */
interface SeedExtractorInterface
{
    /**
     * @return list<ScoredNeuron> seeds triés par score décroissant
     */
    public function extract(RetrievalQuery $query): array;
}
