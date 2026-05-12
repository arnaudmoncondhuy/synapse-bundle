<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum;

/**
 * Nature sémantique d'une synapse — 9 types pour raisonner typé.
 *
 * Permet au système (et au LLM consommateur) de distinguer cause vs
 * corrélation vs contradiction vs composition, etc. Cf. {@link docs/brain-v3-design.md} §6.
 *
 * - **Causal** : A entraîne B (relation causale forte)
 * - **Corroborates** : A et B se confirment mutuellement (corroboration)
 * - **Contradicts** : A et B sont incompatibles (contradiction explicite)
 * - **Composes** : A est composé de B (méréologie : tout/partie)
 * - **Instantiates** : A est une instance de B (instanciation / typologie)
 * - **Temporal** : A précède / suit B dans le temps
 * - **Spatial** : A est lié à B par une relation spatiale
 * - **Emotional** : A est lié à B par un marqueur affectif
 * - **Generic** : relation indéterminée — à éviter au-delà d'un certain seuil
 */
enum SynapseRelationType: string
{
    case Causal = 'causal';
    case Corroborates = 'corroborates';
    case Contradicts = 'contradicts';
    case Composes = 'composes';
    case Instantiates = 'instantiates';
    case Temporal = 'temporal';
    case Spatial = 'spatial';
    case Emotional = 'emotional';
    case Generic = 'generic';
}
