<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\ExtractionResult;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\MultiAreaExtractorInterface;
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
     * @param iterable<NeuronExtractorInterface> $extractors injectés via tag DI (synapse.brain.neuron_extractor)
     * @param LoggerInterface $logger pour signaler les collisions (PSR-3)
     * @param ?MultiAreaExtractorInterface $multiAreaExtractor extracteur "1 passe" optionnel (jalon 3+)
     */
    public function __construct(
        iterable $extractors,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?MultiAreaExtractorInterface $multiAreaExtractor = null,
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
     * Extrait des neurones dans **toutes les aires supportées** en 1 passe
     * LLM (jalon 3 — mode multi-aires).
     *
     * Si un {@see MultiAreaExtractorInterface} est injecté (typiquement
     * `OnePassMultiAreaExtractor`), il est utilisé directement — 1 appel LLM
     * unique qui retourne les neurones des 4 aires actives.
     *
     * Sinon, fallback séquentiel mono-aire : chaque extracteur mono-aire est
     * appelé séparément (N appels LLM, plus coûteux). Pratique pour les
     * setups minimaux ou tests sans le multi-area extractor.
     *
     * @throws ExtractionFailedException si l'extracteur multi-aires échoue
     *
     * @return list<ExtractionResult>
     */
    public function extractAll(MemorySource $source): array
    {
        if (null !== $this->multiAreaExtractor) {
            return $this->multiAreaExtractor->extractAll($source);
        }

        // Fallback séquentiel mono-aire — un appel par extracteur unique.
        // Dedupe par identité d'instance (spl_object_id) plutôt que par classe :
        // si 2 instances de la même classe sont enregistrées sur 2 aires
        // distinctes, ce sont quand même 2 extracteurs distincts à invoquer.
        // Si UN extracteur supporte plusieurs aires (instance unique référencée
        // depuis plusieurs entrées de $extractorsByArea), on l'invoque une fois.
        $results = [];
        $alreadyRun = [];

        foreach ($this->extractorsByArea as $extractor) {
            $key = spl_object_id($extractor);
            if (isset($alreadyRun[$key])) {
                continue;
            }
            $alreadyRun[$key] = true;

            $area = $extractor->supportedAreas()[0] ?? null;
            if (null === $area) {
                continue;
            }

            try {
                $results[] = $extractor->extract($source, $area);
            } catch (ExtractionFailedException $e) {
                $this->logger->warning(
                    sprintf(
                        'MemoryExtractor::extractAll: extracteur %s a échoué sur aire "%s" — skip. %s',
                        $extractor::class,
                        $area->value,
                        $e->getMessage(),
                    ),
                );
                continue;
            }
        }

        return $results;
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
