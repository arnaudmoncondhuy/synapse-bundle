<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Contrat des extracteurs **multi-aires** — produisent des neurones de
 * plusieurs aires en **une seule passe LLM**.
 *
 * Distinct de {@see NeuronExtractorInterface} (mono-aire, jalon 2). Une
 * source brute est traitée en un appel LLM dont la sortie indique dans
 * quelles aires des neurones ont été produits (sélectivité naturelle,
 * design §38).
 *
 * Cette approche est privilégiée à partir du jalon 3 car :
 * - Traitement unifié en une passe LLM (vs N passes mono-aire)
 * - 1 appel LLM au lieu de N (économie de tokens)
 * - Un stimulus active simultanément les aires pertinentes (cohérence
 *   avec la modélisation multi-aires, vs une cascade séquentielle)
 *
 * Les extracteurs mono-aire du jalon 2 restent disponibles pour les apps
 * hôtes qui veulent contrôler finement le routage.
 *
 * Cf. {@link docs/brain/06-phases/jalon-3-ingestion-multi-aires.md} §4.1
 * et la décision orientée du plan jalon 2.
 */
interface MultiAreaExtractorInterface
{
    /**
     * Aires que cet extracteur peut produire en une passe.
     *
     * @return list<BrainArea>
     */
    public function supportedAreas(): array;

    /**
     * Produit des neurones dans plusieurs aires depuis une source unique,
     * via 1 seul appel LLM.
     *
     * Retourne un `ExtractionResult` **par aire** dans laquelle des
     * neurones ont été produits. Une aire qui ne produit rien (sélectivité
     * naturelle) n'apparaît pas dans le résultat — utiliser
     * `ExtractionResult::empty($area)` si on veut forcer la présence.
     *
     * @throws ExtractionFailedException si l'appel LLM échoue, si le
     *                                   structured output est mal formé,
     *                                   ou si le modèle ne supporte pas
     *                                   response_schema
     *
     * @return list<ExtractionResult>
     */
    public function extractAll(MemorySource $source): array;
}
