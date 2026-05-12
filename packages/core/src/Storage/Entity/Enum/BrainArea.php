<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum;

/**
 * Les 7 aires cérébrales du modèle Brain v3.
 *
 * Cinq aires d'**association** (plasticité Hebbienne, persistance durable) :
 *
 * - **Episodic** (Hippocampe) : événements situés dans temps × lieu × acteurs
 * - **Semantic** (Néocortex) : faits stables subject-predicate-value
 * - **Encyclopedic** (Cortex temporal) : documents chunkés indexables (ex-RAG)
 * - **Procedural** (Ganglions de la base) : workflows typés
 * - **Emotional** (Amygdale) : marqueurs affectifs (valence + intensité) ciblés
 *
 * Deux aires de **transduction** (conversion I/O, sans plasticité Hebbienne) :
 *
 * - **Sensory** (Cortex sensoriel) : flux d'entrée brut, transitoire
 * - **Motor** (Cortex moteur) : commandes de sortie planifiées / exécutées
 *
 * Une aire = un schéma SQL distinct (pas de table polymorphe).
 * Cf. {@link docs/brain-v3-design.md} §4 et `docs/brain/00-charte.md` §2.3.
 */
enum BrainArea: string
{
    case Episodic = 'episodic';
    case Semantic = 'semantic';
    case Encyclopedic = 'encyclopedic';
    case Procedural = 'procedural';
    case Emotional = 'emotional';
    case Sensory = 'sensory';
    case Motor = 'motor';

    /**
     * Aires d'association : plasticité Hebbienne active, persistance durable.
     *
     * @return list<self>
     */
    public static function associationAreas(): array
    {
        return [
            self::Episodic,
            self::Semantic,
            self::Encyclopedic,
            self::Procedural,
            self::Emotional,
        ];
    }

    /**
     * Aires de transduction : neurones de conversion I/O, pas de plasticité Hebbienne.
     *
     * @return list<self>
     */
    public static function transductionAreas(): array
    {
        return [
            self::Sensory,
            self::Motor,
        ];
    }

    public function isAssociation(): bool
    {
        return in_array($this, self::associationAreas(), true);
    }

    public function isTransduction(): bool
    {
        return in_array($this, self::transductionAreas(), true);
    }
}
