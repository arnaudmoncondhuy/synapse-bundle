<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\ExtractionResult;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\NeuronExtractorInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrateur de l'extraction de neurones depuis une `MemorySource`.
 *
 * **Jalon 2 — mode mono-aire** : sélectionne l'extracteur compétent pour
 * l'aire demandée et délègue. Une seule aire à la fois.
 *
 * **Jalon 3 — mode 1 passe unique** : on remplacera ce dispatch par un
 * extracteur multi-aires unique qui reçoit la source + le schéma des
 * 7 aires, et retourne `{aire: contenu_extrait}` (cf. décision orientée
 * plan jalon 2).
 *
 * Les extracteurs sont injectés via le tag DI `synapse.brain.neuron_extractor`
 * (autoconfigure NeuronExtractorInterface). MemoryExtractor les indexe par
 * aire supportée au boot.
 *
 * **Collision** : si plusieurs extracteurs déclarent supporter la même aire,
 * le dernier enregistré gagne. Un warning est loggé pour signaler la collision
 * (audit code reviewer jalon 2 point e).
 *
 * Cf. {@link docs/brain/06-phases/jalon-2-ingestion-mono-aire.md} §4.4.
 */
final class MemoryExtractor
{
    /**
     * @var array<value-of<BrainArea>, NeuronExtractorInterface>
     */
    private array $extractorsByArea = [];

    /**
     * @var list<BrainArea>
     */
    private array $supportedAreasCache;

    /**
     * @param iterable<NeuronExtractorInterface> $extractors injectés via tag DI
     */
    public function __construct(
        iterable $extractors,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        foreach ($extractors as $extractor) {
            foreach ($extractor->supportedAreas() as $area) {
                if (isset($this->extractorsByArea[$area->value])) {
                    $this->logger->warning(
                        sprintf(
                            'MemoryExtractor: collision sur l\'aire "%s" — l\'extracteur %s remplace %s. Le dernier enregistré gagne.',
                            $area->value,
                            $extractor::class,
                            $this->extractorsByArea[$area->value]::class,
                        ),
                    );
                }
                $this->extractorsByArea[$area->value] = $extractor;
            }
        }

        // Cache résolu une fois au boot — la liste est figée après construction
        $this->supportedAreasCache = $this->resolveSupportedAreas();
    }

    /**
     * Extrait des neurones de l'aire demandée depuis la source.
     *
     * @throws ExtractionFailedException si aucun extracteur n'est enregistré
     *                                   pour l'aire visée ou si l'extracteur
     *                                   lui-même échoue
     */
    public function extract(MemorySource $source, BrainArea $area): ExtractionResult
    {
        $extractor = $this->extractorsByArea[$area->value] ?? null;
        if (null === $extractor) {
            throw new ExtractionFailedException($source, $area, sprintf('no extractor registered for area "%s"', $area->value));
        }

        return $extractor->extract($source, $area);
    }

    /**
     * Aires actuellement supportées par les extracteurs enregistrés.
     *
     * @return list<BrainArea>
     */
    public function supportedAreas(): array
    {
        return $this->supportedAreasCache;
    }

    /**
     * @return list<BrainArea>
     */
    private function resolveSupportedAreas(): array
    {
        $areas = [];
        foreach (array_keys($this->extractorsByArea) as $value) {
            $area = BrainArea::tryFrom($value);
            if (null !== $area) {
                $areas[] = $area;
            }
        }

        return $areas;
    }
}
