<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Bridge\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

/**
 * Préfixe les tables des entités Brain v3 (brain_*, core_*) au runtime
 * Doctrine via `loadClassMetadata`.
 *
 * Permet à l'app hôte de surcharger le préfixe (`synapse.persistence.table_prefix`,
 * défaut `syn_`) pour cohabiter avec ses propres tables sans collision.
 *
 * **Sélectivité** : seules les entités appartenant au namespace
 * `ArnaudMoncondhuy\SynapseCore\` (et plus largement `ArnaudMoncondhuy\Synapse*`
 * — cohérence cross-packages admin/chat/core qui partagent la même base)
 * ET dont la table déclarée commence par `brain_` ou `core_` sont préfixées.
 *
 * Les anciennes tables `synapse_*` sont laissées telles quelles (transition
 * douce — elles seront renommées en `core_*` au jalon 8).
 *
 * Utilise l'attribute `#[AsDoctrineListener]` (Doctrine ORM 3+) plutôt
 * que l'interface `EventSubscriber` (dépréciée en ORM 3, retrait imminent).
 *
 * Cf. {@link docs/brain/05-decisions/001-prefixe-table-configurable.md}
 * (ADR-001 — accepté).
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final readonly class TablePrefixSubscriber
{
    /**
     * Préfixes de tables qui doivent être préfixés par le subscriber.
     */
    private const PREFIXABLE_TABLE_PREFIXES = ['brain_', 'core_'];

    /**
     * Namespace racine des entités du bundle (couvre core, admin, chat).
     */
    private const SYNAPSE_NAMESPACE = 'ArnaudMoncondhuy\\Synapse';

    public function __construct(private string $prefix)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $className = $metadata->getName();

        // Hors du namespace Synapse → ignorer (laisse les apps hôtes tranquilles)
        if (!str_starts_with($className, self::SYNAPSE_NAMESPACE)) {
            return;
        }

        $tableName = $metadata->getTableName();

        if (!$this->shouldPrefix($tableName)) {
            return;
        }

        // Idempotence : si déjà préfixé (ex: à la recharge metadata), ne rien faire
        if (str_starts_with($tableName, $this->prefix)) {
            return;
        }

        $metadata->setPrimaryTable(['name' => $this->prefix.$tableName]);
    }

    private function shouldPrefix(string $tableName): bool
    {
        foreach (self::PREFIXABLE_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($tableName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
