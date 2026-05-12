<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SemanticNeuron>
 */
class SemanticNeuronRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SemanticNeuron::class);
    }

    /**
     * Neurones sémantiques portant sur un sujet donné, ordonnés par
     * confiance décroissante.
     *
     * @return list<SemanticNeuron>
     */
    public function findBySubject(string $subject): array
    {
        /** @var list<SemanticNeuron> $result */
        $result = $this->findBy(['subject' => $subject], ['confidence' => 'DESC']);

        return $result;
    }
}
