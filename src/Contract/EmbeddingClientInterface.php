<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

interface EmbeddingClientInterface
{
    /**
     * Génère des embeddings vectoriels pour un ou plusieurs textes d'entrée.
     *
     * @param string|array<string> $input Texte unique ou tableau de textes
     * @param string|null          $model Modèle d'embedding spécifique (override de la config)
     * @param array                $options Options additionnelles (ex: output_dimensionality)
     *
     * @return array Structure de retour standardisée :
     *               [
     *                   'embeddings' => [
     *                       [0.123, -0.456, ...], // Vecteur du 1er texte
     *                       ...
     *                   ],
     *                   'usage' => [
     *                       'prompt_tokens' => int,
     *                       'total_tokens'  => int,
     *                   ]
     *               ]
     */
    public function generateEmbeddings(string|array $input, ?string $model = null, array $options = []): array;
}
