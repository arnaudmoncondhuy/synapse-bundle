<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface pour l'Inversion de Contrôle de la mémoire vectorielle (RAG).
 * 
 * Permet au bundle Synapse de déléguer le stockage physique et la recherche 
 * de similitude à l'application hôte (ex: via pgvector, Qdrant, etc.).
 */
interface VectorStoreInterface
{
    /**
     * Sauvegarde un vecteur et ses métadonnées dans la mémoire.
     *
     * @param float[] $vector  Le vecteur d'embedding
     * @param array   $payload Métadonnées associées (texte original, IDs, etc.)
     */
    public function saveMemory(array $vector, array $payload): void;

    /**
     * Recherche les éléments les plus similaires à un vecteur donné.
     *
     * @param float[] $vector Le vecteur de recherche
     * @param int     $limit  Nombre maximum de résultats
     * 
     * @return array<int, array{payload: array, score: float}>
     */
    public function searchSimilar(array $vector, int $limit = 5): array;
}
