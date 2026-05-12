<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Extrait un événement situé depuis une source brute via un appel LLM avec
 * structured output (ADR-003).
 *
 * 1 appel LLM = **0 ou 1** `EpisodicNeuron` (un événement par source max ;
 * si la source ne décrit pas d'événement, retour vide — sélectivité naturelle).
 *
 * Le prompt et le JSON schema sont versionnés dans `Resources/brain/prompts/`
 * (ADR-004). Le squelette d'appel LLM est dans {@see AbstractLlmExtractor}.
 *
 * Cf. {@link docs/brain/06-phases/jalon-2-ingestion-mono-aire.md} §4.3.
 */
final readonly class EpisodicExtractor extends AbstractLlmExtractor
{
    protected function promptFileName(): string
    {
        return 'extract-episodic';
    }

    protected function expectedArea(): BrainArea
    {
        return BrainArea::Episodic;
    }

    protected function chatAction(): string
    {
        return 'extract_episodic';
    }

    /**
     * Inclut `receivedAt` pour aider le LLM à résoudre les références
     * temporelles relatives ("hier", "ce matin") par rapport à un point
     * de référence connu.
     */
    protected function buildMessageContext(MemorySource $source): string
    {
        return 'Reçue : '.$source->getReceivedAt()->format('c');
    }

    protected function buildNeurons(MemorySource $source, array $structured): array
    {
        if (!array_key_exists('episode', $structured)) {
            throw new ExtractionFailedException($source, BrainArea::Episodic, 'invalid structured output: missing "episode" key');
        }

        $episode = $structured['episode'];

        // Le LLM a déclaré que la source ne contient pas d'événement situé
        if (null === $episode) {
            return [[], ['reason' => 'llm_returned_null']];
        }

        if (!is_array($episode)) {
            throw new ExtractionFailedException($source, BrainArea::Episodic, 'invalid structured output: "episode" must be object or null');
        }

        $occurredAtRaw = $episode['occurred_at'] ?? null;
        $eventSummary = $episode['event_summary'] ?? null;
        $actorsRaw = $episode['actors'] ?? [];
        $location = $episode['location'] ?? null;

        if (!is_string($occurredAtRaw) || !is_string($eventSummary)) {
            throw new ExtractionFailedException($source, BrainArea::Episodic, 'invalid episode object: occurred_at and event_summary must be strings');
        }

        try {
            $occurredAt = new \DateTimeImmutable($occurredAtRaw);
        } catch (\Exception $e) {
            throw new ExtractionFailedException($source, BrainArea::Episodic, sprintf('invalid occurred_at value "%s": %s', $occurredAtRaw, $e->getMessage()), $e);
        }

        $actors = [];
        if (is_array($actorsRaw)) {
            foreach ($actorsRaw as $actor) {
                if (is_string($actor)) {
                    $actors[] = $actor;
                }
            }
        }

        if (null !== $location && !is_string($location)) {
            $location = null;
        }

        $neuron = new EpisodicNeuron(
            source: $source,
            occurredAt: $occurredAt,
            eventSummary: $eventSummary,
            actors: $actors,
            location: $location,
        );

        return [[$neuron], []];
    }
}
