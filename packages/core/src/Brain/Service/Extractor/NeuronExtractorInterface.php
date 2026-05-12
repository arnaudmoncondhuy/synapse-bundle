<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Contrat implémenté par tout extracteur de neurones depuis une source.
 *
 * Au jalon 2, un extracteur produit des neurones pour **une seule aire**
 * (mono-aire). Au jalon 3, l'orchestrateur `MemoryExtractor` introduira un
 * mode "1 passe unique" multi-aires distinct (cf. décision orientée plan
 * jalon 2).
 *
 * **Idempotence et immutabilité de la source** : l'extracteur ne modifie
 * jamais la `MemorySource` reçue. Il peut être rejoué autant de fois que
 * nécessaire (re-vectorisation après changement de prompt ou de modèle —
 * pattern Prisma, cf. design §9).
 *
 * Cf. {@link docs/brain/06-phases/jalon-2-ingestion-mono-aire.md}.
 */
interface NeuronExtractorInterface
{
    /**
     * Aires que cet extracteur peut produire.
     *
     * Permet à l'orchestrateur `MemoryExtractor` de router une demande
     * vers l'extracteur compétent.
     *
     * @return list<BrainArea>
     */
    public function supportedAreas(): array;

    /**
     * Produit des neurones de l'aire visée à partir de la source.
     *
     * @throws ExtractionFailedException si l'extraction échoue (LLM down,
     *                                   JSON invalide, modèle sans support
     *                                   response_schema, etc.)
     */
    public function extract(MemorySource $source, BrainArea $targetArea): ExtractionResult;
}
