<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence;

/**
 * Calcul de similarité cosine entre deux vecteurs.
 *
 * Pure function, sans état. Utilisé par {@see ConvergenceDetector} et les
 * outils de bench pour comparer des embeddings.
 *
 * À long terme (jalon 5+), envisager de déplacer le calcul côté pgvector
 * (`<=>` opérateur) pour de la perf à l'échelle. Pour le jalon 3, on
 * calcule en PHP — vectorisé via `array_map`, suffisamment rapide pour
 * <10k neurones par requête.
 */
final readonly class CosineSimilarity
{
    /**
     * Cosine similarity entre 2 vecteurs de même dimension.
     *
     * Retourne :
     * - 1.0 si vecteurs identiques (direction)
     * - 0.0 si vecteurs orthogonaux (non liés)
     * - -1.0 si vecteurs opposés (rare en embeddings)
     *
     * Retourne 0.0 si l'un des vecteurs est vide ou de norme nulle (cas
     * dégénéré : on traite comme "pas de similarité mesurable").
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function compute(array $a, array $b): float
    {
        if ([] === $a || [] === $b) {
            return 0.0;
        }
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException(sprintf('CosineSimilarity: vectors must have the same dimension, got %d and %d', count($a), count($b)));
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $va) {
            $vb = $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }

        if (0.0 === $normA || 0.0 === $normB) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
