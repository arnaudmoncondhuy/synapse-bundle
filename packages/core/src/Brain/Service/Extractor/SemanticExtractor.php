<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
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
 * (ADR-004).
 *
 * Cf. {@link docs/brain/06-phases/jalon-2-ingestion-mono-aire.md} §4.3.
 */
final readonly class SemanticExtractor implements NeuronExtractorInterface
{
    private const PROMPT_PATH = __DIR__.'/../../../Resources/brain/prompts/extract-semantic.md';
    private const SCHEMA_PATH = __DIR__.'/../../../Resources/brain/prompts/extract-semantic.schema.json';

    public function __construct(
        private ChatService $chatService,
    ) {
    }

    /**
     * @return list<BrainArea>
     */
    public function supportedAreas(): array
    {
        return [BrainArea::Semantic];
    }

    public function extract(MemorySource $source, BrainArea $targetArea): ExtractionResult
    {
        if (BrainArea::Semantic !== $targetArea) {
            throw new ExtractionFailedException($source, $targetArea, sprintf('SemanticExtractor does not support area "%s"', $targetArea->value));
        }

        $prompt = $this->loadPrompt();
        $schema = $this->loadSchema();
        $message = $this->buildMessage($source, $prompt);

        try {
            $result = $this->chatService->ask($message, [
                'structured_output' => $schema,
                'module' => 'brain',
                'action' => 'extract_semantic',
            ]);
        } catch (\Throwable $e) {
            throw new ExtractionFailedException($source, $targetArea, 'ChatService call failed: '.$e->getMessage(), $e);
        }

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured) || !isset($structured['facts']) || !is_array($structured['facts'])) {
            throw new ExtractionFailedException($source, $targetArea, 'invalid structured output: missing "facts" array');
        }

        $neurons = [];
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

            $neurons[] = new SemanticNeuron(
                firstSource: $source->getId(),
                subject: $subject,
                predicate: $predicate,
                value: $value,
                confidence: (float) $confidence,
            );
        }

        return new ExtractionResult(
            area: BrainArea::Semantic,
            neurons: $neurons,
            debug: [
                'model' => $result['model'] ?? 'unknown',
                'usage' => $result['usage'] ?? [],
                'extracted_count' => count($neurons),
                'raw_count' => count($structured['facts']),
            ],
        );
    }

    private function loadPrompt(): string
    {
        $contents = @file_get_contents(self::PROMPT_PATH);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('SemanticExtractor: unable to read prompt at %s', self::PROMPT_PATH));
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(): array
    {
        $contents = @file_get_contents(self::SCHEMA_PATH);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('SemanticExtractor: unable to read schema at %s', self::SCHEMA_PATH));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function buildMessage(MemorySource $source, string $prompt): string
    {
        $payload = json_encode($source->getRawPayload(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $prompt."\n\n## Source à analyser\n\nProvider : ".$source->getProvider()."\n\nPayload :\n```json\n".$payload."\n```\n";
    }
}
