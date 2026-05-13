<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

/**
 * Propage le score depuis un ensemble de seeds à travers le graphe Hebbien.
 *
 * Existe pour permettre le mocking en test ({@see SpreadingActivation} est
 * `final readonly` et non doublable par PHPUnit).
 */
interface SpreadingActivationInterface
{
    /**
     * @param list<ScoredNeuron> $seeds
     *
     * @return list<ScoredNeuron> trié par score décroissant, tronqué à `query.topN`
     */
    public function spread(array $seeds, RetrievalQuery $query): array;
}
