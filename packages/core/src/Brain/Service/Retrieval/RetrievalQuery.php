<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use Symfony\Component\Uid\Uuid;

/**
 * Requête de retrieval Brain — utilisée par {@see MemoryRetriever}.
 *
 * Immutable. Représente une intention de retrouver des neurones depuis
 * une expression naturelle (typiquement la dernière entrée user d'une
 * conversation, ou une requête CLI).
 *
 * Le filtrage par `ownerId` est appliqué côté retriever pour respecter
 * l'isolation user (ADR-006) :
 * - `ownerId = Uuid` → retrieve les neurones de cet user + ceux de la
 *   couche open (sources sans owner)
 * - `ownerId = null` → retrieve uniquement les neurones de la couche open
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.1.
 */
final readonly class RetrievalQuery
{
    /**
     * @param string $text requête en langage naturel
     * @param ?Uuid $ownerId owner de l'utilisateur appelant (null = couche open)
     * @param int $topN nombre max de résultats à retourner (default 10)
     * @param int $maxDepth borne supérieure de profondeur BFS — **sécurité**, pas critère
     *                      métier (default 3, plafonné par SpreadingActivation::HARD_MAX_DEPTH).
     *                      Cf. ADR-007 amendé + revue littérature (convergence EcphoryRAG/SA-RAG/
     *                      SCG-MEM : gain nul au-delà de 3 hops).
     * @param float $minScore **critère d'arrêt principal** : tout neurone touché avec score
     *                        cumulé < minScore n'est pas inclus dans le résultat ET ne propage
     *                        plus loin. Default 0.1 (en dessous = bruit). Cf. ADR-007 amendé.
     */
    public function __construct(
        public string $text,
        public ?Uuid $ownerId = null,
        public int $topN = 10,
        public int $maxDepth = 3,
        public float $minScore = 0.1,
    ) {
        if ('' === trim($this->text)) {
            throw new \InvalidArgumentException('RetrievalQuery: text cannot be empty.');
        }
        if ($this->topN < 1) {
            throw new \InvalidArgumentException('RetrievalQuery: topN must be >= 1.');
        }
        if ($this->maxDepth < 0) {
            throw new \InvalidArgumentException('RetrievalQuery: maxDepth must be >= 0.');
        }
        if ($this->minScore < 0.0 || $this->minScore > 1.0) {
            throw new \InvalidArgumentException('RetrievalQuery: minScore must be in [0, 1].');
        }
    }
}
