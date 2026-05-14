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
     * Sémantique de la valeur de retour :
     * - `null` = neurone "open" légitime : soit le neurone n'a pas de
     *   sourceUuid (procédural manuel), soit sa MemorySource existe avec
     *   `ownerId === null`. Admissible pour tout queryOwner.
     * - `Uuid` = neurone privé d'un user. Admissible uniquement pour
     *   ce user (ou si la source est partagée explicitement à l'avenir).
     *
     * **Cas particulier : MemorySource introuvable** (neurone orphelin).
     * On retourne `self::ORPHAN_OWNER` (Uuid sentinelle non-zéro) plutôt
     * que `null`. Raison : fail-closed contre le scénario où une source
     * privée a été supprimée sans purger ses neurones — l'audit
     * `brain-isolation-paranoid` 2026-05-14 a montré que retourner `null`
     * (open) dans ce cas exposait les neurones orphelins à tout queryOwner.
     *
     * La sentinelle ne matche aucun user réel (Uuid::v4 statique généré
     * une fois), donc `isAdmissibleForOwner(ORPHAN_OWNER, $anyOwner)`
     * retourne toujours `false`. Les neurones orphelins sont silencieusement
     * exclus du retrieval.
     */
    public function resolve(MemoryFragment $neuron): ?Uuid
    {
        $sourceUuid = $neuron->getSourceUuid();
        if (null === $sourceUuid) {
            // Neurone sans source (procédural manuel). Open par défaut — pas un cas d'orphelinage.
            return null;
        }

        $key = $sourceUuid->toRfc4122();
        if (\array_key_exists($key, $this->cache)) {
            $cached = $this->cache[$key];

            return false === $cached ? self::orphanOwner() : $cached;
        }

        $source = $this->sourceRepository->find($sourceUuid);
        if (null === $source) {
            $this->cache[$key] = false;

            // Fail-closed : neurone orphelin → sentinelle non-admissible
            return self::orphanOwner();
        }

        $ownerId = $source->getOwnerId();
        $this->cache[$key] = $ownerId;

        return $ownerId;
    }

    /**
     * Sentinelle Uuid pour les neurones orphelins (MemorySource supprimée).
     *
     * Cache statique : généré une fois au premier appel, partagé par toutes
     * les instances. Volontairement un Uuid v4 plutôt que v7 pour qu'il ne
     * puisse pas être confondu avec un user réel généré chronologiquement.
     */
    private static function orphanOwner(): Uuid
    {
        static $sentinel = null;
        if (null === $sentinel) {
            $sentinel = Uuid::v4();
        }

        return $sentinel;
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
