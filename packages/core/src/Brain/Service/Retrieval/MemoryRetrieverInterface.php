<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

/**
 * Orchestrateur du retrieval Brain.
 *
 * Existe pour permettre le mocking en test ({@see MemoryRetriever} est
 * `final readonly` et donc non doublable par PHPUnit). Sera nécessaire
 * dès l'étape 9 du jalon 4 (`BrainContextSubscriber`) qui dépendra du
 * retriever et devra être testé indépendamment.
 */
interface MemoryRetrieverInterface
{
    public function retrieve(RetrievalQuery $query): RetrievalResult;
}
