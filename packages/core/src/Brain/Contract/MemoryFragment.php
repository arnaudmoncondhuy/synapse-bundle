<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use Symfony\Component\Uid\Uuid;

/**
 * Contrat implémenté par toute entité de neurone — un fragment de mémoire
 * dans une aire cérébrale spécifique.
 *
 * Les 7 aires (Episodic, Semantic, Encyclopedic, Procedural, Emotional,
 * Sensory, Motor) ont chacune leur entité Doctrine avec son schéma propre,
 * mais elles partagent ce contrat pour participer au système polymorphe
 * de {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse}.
 *
 * **Pourquoi un contrat sans table parente** : on ne veut pas d'héritage
 * Doctrine (single-table ou joined) car les schémas par aire sont trop
 * différents (cf. charte §2.3). Le polymorphisme est résolu en code via
 * le couple (`area`, `id`) — la Synapse stocke ces deux champs, et un
 * service de résolution les déréférence vers la bonne entité.
 *
 * Cf. {@link docs/brain-v3-design.md} §4 et `docs/brain/03-audit-existant.md`.
 */
interface MemoryFragment
{
    /**
     * Identifiant UUID du neurone.
     */
    public function getId(): Uuid;

    /**
     * Aire cérébrale d'appartenance.
     *
     * Doit être constant pour une classe d'entité donnée (l'aire ne change
     * pas pour un neurone). Sert de discriminant côté Synapse.
     */
    public function getArea(): BrainArea;

    /**
     * UUID de la source de stimulus dont ce neurone est dérivé.
     *
     * Peut être null pour les neurones créés manuellement (ex: `manual`
     * source ou neurones procéduraux écrits à la main).
     */
    public function getSourceUuid(): ?Uuid;
}
