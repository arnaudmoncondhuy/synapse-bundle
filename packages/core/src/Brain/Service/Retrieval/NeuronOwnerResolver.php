<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\MemorySourceRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Résout l'ownerId d'un neurone via sa MemorySource d'origine.
 *
 * **Pourquoi ce service** : ADR-006 (isolation user) garantit qu'une synapse
 * ne peut pas lier 2 users différents. Mais le **seed** et le **neurone
 * visité** au retrieval doivent aussi être filtrés par owner — sinon Bob
 * peut voir un neurone d'Alice si l'embedding match (audit `brain-isolation-paranoid`
 * 2026-05-13 — fuite scénario B confirmée).
 *
 * Pour l'instant : 1 lookup `MemorySourceRepository::find()` par neurone,
 * **mémoïsé dans un cache local** (par instance) pour éviter le N+1 sur une
 * même session de retrieval.
 *
 * **Migration jalon 5+** : dénormaliser `owner_id` directement sur chaque
 * entité Neuron (comme déjà fait sur Synapse). Élimine le lookup et permet
 * un filtrage SQL côté repository (`findCandidatesForOwner($ownerId)`).
 *
 * Cf. ADR-006, audit `brain-isolation-paranoid` 2026-05-13.
 */
final class NeuronOwnerResolver implements NeuronOwnerResolverInterface
{
    /**
     * Cache local des owners résolus, indexé par UUID de MemorySource.
     *
     * `false` représente "MemorySource introuvable" (distinct de `null` qui
     * signifie "MemorySource trouvée mais ownerId null = open").
     *
     * @var array<string, false|Uuid|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly MemorySourceRepository $sourceRepository,
    ) {
    }

    /**
     * Retourne l'ownerId d'un neurone (résolu via sa MemorySource d'origine).
     *
     * - `null` = neurone "open" (source sans ownerId, ou source avec ownerId null)
     * - `Uuid` = neurone privé d'un user
     * - throw si le neurone n'a pas de sourceUuid (cas anormal, neurone orphelin)
     */
    public function resolve(MemoryFragment $neuron): ?Uuid
    {
        $sourceUuid = $neuron->getSourceUuid();
        if (null === $sourceUuid) {
            // Neurone sans source (manuel, procédural écrit à la main). Open par défaut.
            return null;
        }

        $key = $sourceUuid->toRfc4122();
        if (\array_key_exists($key, $this->cache)) {
            $cached = $this->cache[$key];

            return false === $cached ? null : $cached;
        }

        $source = $this->sourceRepository->find($sourceUuid);
        if (null === $source) {
            $this->cache[$key] = false;

            return null;
        }

        $ownerId = $source->getOwnerId();
        $this->cache[$key] = $ownerId;

        return $ownerId;
    }

    /**
     * - queryOwner = null (anonyme) → admet uniquement neurones 100% open
     * - queryOwner = Alice → admet neurones d'Alice ou open.
     *
     * Mêmes règles que `Synapse::assertUserIsolation` (ADR-006).
     */
    public function isAdmissibleForOwner(?Uuid $neuronOwner, ?Uuid $queryOwner): bool
    {
        if (null === $queryOwner) {
            return null === $neuronOwner;
        }

        return null === $neuronOwner || $neuronOwner->equals($queryOwner);
    }
}
