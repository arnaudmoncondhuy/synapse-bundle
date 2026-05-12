<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum;

/**
 * Polarité d'une synapse — amplification ou inhibition du signal.
 *
 * - **Excitatory** : le neurone source renforce l'activation du neurone cible.
 *   Cas par défaut : "deux faits qui se renforcent l'un l'autre".
 * - **Inhibitory** : le neurone source réduit l'activation du neurone cible.
 *   Permet de modéliser contradictions et exclusions (ex: "en congé"
 *   inhibe "RDV proposable matin").
 *
 * La polarité s'ajoute à la dimension `weight` : on peut avoir des liens
 * négatifs sémantiquement signifiants (contradiction, exclusion), pas
 * seulement une force d'activation.
 *
 * Cf. {@link docs/brain-v3-design.md} §6.
 */
enum SynapsePolarity: string
{
    case Excitatory = 'excitatory';
    case Inhibitory = 'inhibitory';
}
