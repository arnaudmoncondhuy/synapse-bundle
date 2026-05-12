<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
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
 * (ADR-004).
 *
 * Cf. {@link docs/brain/06-phases/jalon-2-ingestion-mono-aire.md} §4.3.
 */
final readonly class EpisodicExtractor implements NeuronExtractorInterface
{
    private const PROMPT_PATH = __DIR__.'/../../../Resources/brain/prompts/extract-episodic.md';
    private const SCHEMA_PATH = __DIR__.'/../../../Resources/brain/prompts/extract-episodic.schema.json';

    public function __construct(
        private ChatService $chatService,
    ) {
    }

    /**
     * @return list<BrainArea>
     */
    public function supportedAreas(): array
    {
        return [BrainArea::Episodic];
    }

    public function extract(MemorySource $source, BrainArea $targetArea): ExtractionResult
    {
        if (BrainArea::Episodic !== $targetArea) {
            throw new ExtractionFailedException($source, $targetArea, sprintf('EpisodicExtractor does not support area "%s"', $targetArea->value));
        }

        $prompt = $this->loadPrompt();
        $schema = $this->loadSchema();
        $message = $this->buildMessage($source, $prompt);

        try {
            $result = $this->chatService->ask($message, [
                'structured_output' => $schema,
                'module' => 'brain',
                'action' => 'extract_episodic',
            ]);
        } catch (\Throwable $e) {
            throw new ExtractionFailedException($source, $targetArea, 'ChatService call failed: '.$e->getMessage(), $e);
        }

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured) || !array_key_exists('episode', $structured)) {
            throw new ExtractionFailedException($source, $targetArea, 'invalid structured output: missing "episode" key');
        }

        $episode = $structured['episode'];
        $debug = [
            'model' => $result['model'] ?? 'unknown',
            'usage' => $result['usage'] ?? [],
        ];

        // Le LLM a déclaré que la source ne contient pas d'événement situé
        if (null === $episode) {
            return new ExtractionResult(
                area: BrainArea::Episodic,
                neurons: [],
                debug: $debug + ['extracted_count' => 0, 'reason' => 'llm_returned_null'],
            );
        }

        if (!is_array($episode)) {
            throw new ExtractionFailedException($source, $targetArea, 'invalid structured output: "episode" must be object or null');
        }

        $occurredAtRaw = $episode['occurred_at'] ?? null;
        $eventSummary = $episode['event_summary'] ?? null;
        $actorsRaw = $episode['actors'] ?? [];
        $location = $episode['location'] ?? null;

        if (!is_string($occurredAtRaw) || !is_string($eventSummary)) {
            throw new ExtractionFailedException($source, $targetArea, 'invalid episode object: occurred_at and event_summary must be strings');
        }

        try {
            $occurredAt = new \DateTimeImmutable($occurredAtRaw);
        } catch (\Exception $e) {
            throw new ExtractionFailedException($source, $targetArea, sprintf('invalid occurred_at value "%s": %s', $occurredAtRaw, $e->getMessage()), $e);
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

        return new ExtractionResult(
            area: BrainArea::Episodic,
            neurons: [$neuron],
            debug: $debug + ['extracted_count' => 1],
        );
    }

    private function loadPrompt(): string
    {
        $contents = @file_get_contents(self::PROMPT_PATH);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('EpisodicExtractor: unable to read prompt at %s', self::PROMPT_PATH));
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
            throw new \RuntimeException(sprintf('EpisodicExtractor: unable to read schema at %s', self::SCHEMA_PATH));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function buildMessage(MemorySource $source, string $prompt): string
    {
        $payload = json_encode($source->getRawPayload(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $prompt."\n\n## Source à analyser\n\nProvider : ".$source->getProvider()
            ."\n\nReçue : ".$source->getReceivedAt()->format('c')
            ."\n\nPayload :\n```json\n".$payload."\n```\n";
    }
}
