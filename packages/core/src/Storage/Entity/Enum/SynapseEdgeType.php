<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum;

/**
 * Régime de fonctionnement d'une synapse.
 *
 * - **Association** : edge entre deux aires d'association (Episodic, Semantic,
 *   Encyclopedic, Procedural, Emotional). Soumis à plasticité Hebbienne :
 *   renforcement par co-activation, decay temporel, consolidation.
 *
 * - **Transduction** : edge impliquant une aire de transduction (Sensory ou
 *   Motor). Pas de plasticité, pas de decay — sert uniquement de **trace de
 *   provenance / causalité** (audit trail). Ex : "neurone épisodique A a été
 *   extrait du buffer sensoriel B".
 *
 * Cf. {@link docs/brain-v3-design.md} §7.
 */
enum SynapseEdgeType: string
{
    case Association = 'association';
    case Transduction = 'transduction';
}
