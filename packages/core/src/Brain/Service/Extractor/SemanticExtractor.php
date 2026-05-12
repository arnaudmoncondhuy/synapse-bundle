<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Extrait des faits stables (subject-predicate-value) depuis une source brute
 * via un appel LLM avec structured output (ADR-003).
 *
 * 1 appel LLM = list<SemanticNeuron> (possiblement vide — sélectivité naturelle
 * du LLM autorisée par le prompt).
 *
 * Le prompt et le JSON schema sont versionnés dans `Resources/brain/prompts/`
 * (ADR-004). Le squelette d'appel LLM est dans {@see AbstractLlmExtractor}.
 *
 * Cf. {@link docs/brain/06-phases/jalon-2-ingestion-mono-aire.md} §4.3.
 */
final readonly class SemanticExtractor extends AbstractLlmExtractor
{
    protected function promptFileName(): string
    {
        return 'extract-semantic';
    }

    protected function expectedArea(): BrainArea
    {
        return BrainArea::Semantic;
    }

    protected function chatAction(): string
    {
        return 'extract_semantic';
    }

    /**
     * Pas de contexte additionnel : les faits sémantiques sont a-temporels,
     * l'horodatage de réception n'apporte rien à l'extraction.
     */
    protected function buildMessageContext(MemorySource $source): string
    {
        return '';
    }

    protected function buildNeurons(MemorySource $source, array $structured): array
    {
        if (!isset($structured['facts']) || !is_array($structured['facts'])) {
            throw new \ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException($source, BrainArea::Semantic, 'invalid structured output: missing "facts" array');
        }

        $neurons = [];
        $rawCount = count($structured['facts']);

        foreach ($structured['facts'] as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $subject = $fact['subject'] ?? null;
            $predicate = $fact['predicate'] ?? null;
            $value = $fact['value'] ?? null;
            $confidence = $fact['confidence'] ?? 0.5;

            if (!is_string($subject) || !is_string($predicate) || !is_string($value)) {
                continue;
            }
            if (!is_numeric($confidence)) {
                $confidence = 0.5;
            }
            // Clamp [0, 1] ceinture-et-bretelles : le JSON schema impose
            // minimum/maximum, mais un LLM qui renvoie 1.5 doit être borné.
            $confidence = max(0.0, min(1.0, (float) $confidence));

            $neurons[] = new SemanticNeuron(
                firstSource: $source->getId(),
                subject: $subject,
                predicate: $predicate,
                value: $value,
                confidence: $confidence,
            );
        }

        return [$neurons, ['raw_count' => $rawCount]];
    }
}
