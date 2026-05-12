<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Contract;

/**
 * Sous-contrat de {@see MemoryFragment} pour les neurones qui portent un
 * embedding vectoriel.
 *
 * Implémenté par les 4 aires "embeddables" du modèle Brain v3 : Episodic,
 * Semantic, Encyclopedic. Pas implémenté par Procedural, Emotional,
 * Sensory, Motor (qui ne portent pas d'embedding par design — cf.
 * `docs/brain-v3-design.md` §4 "Vue synthèse").
 *
 * Permet au {@see \ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\ConvergenceDetector}
 * de récupérer l'embedding du candidat sans connaître la classe concrète.
 */
interface EmbeddableNeuron extends MemoryFragment
{
    /**
     * @return list<float>
     */
    public function getEmbedding(): array;
}
