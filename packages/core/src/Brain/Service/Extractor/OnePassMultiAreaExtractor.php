<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Extracteur **multi-aires en 1 passe LLM** (jalon 3).
 *
 * Un seul appel `ChatService::ask` avec un prompt qui décrit les 4 aires
 * actives (Semantic, Episodic, Encyclopedic, Procedural) et un JSON schema
 * regroupé. La sortie LLM peut remplir 0..N aires selon le contenu de la
 * source (sélectivité naturelle, design §38).
 *
 * Préféré aux extracteurs mono-aire à partir du jalon 3 car :
 * - Source traitée en une passe (vs N passes mono-aire)
 * - 1 appel au lieu de N (économie tokens)
 * - Un stimulus active simultanément les aires pertinentes (cohérence
 *   avec la modélisation multi-aires)
 *
 * **Note sur l'aire Encyclopedic** : la sortie LLM produit `chunkable_text`
 * (texte à chunker). On ne crée PAS de chunks ici — c'est le rôle du
 * `EncyclopedicExtractor` (jalon 2). Pour produire des EncyclopedicNeuron
 * effectifs depuis le texte renvoyé, le caller (MemoryExtractor) doit
 * router vers l'EncyclopedicExtractor en seconde passe.
 *
 * Cf. {@link docs/brain/06-phases/jalon-3-ingestion-multi-aires.md} §4.2.
 */
final readonly class OnePassMultiAreaExtractor implements MultiAreaExtractorInterface
{
    private const PROMPT_PATH = __DIR__.'/../../../Resources/brain/prompts/extract-multi-area.md';
    private const SCHEMA_PATH = __DIR__.'/../../../Resources/brain/prompts/extract-multi-area.schema.json';

    public function __construct(
        private ChatService $chatService,
    ) {
    }

    /**
     * @return list<BrainArea>
     */
    public function supportedAreas(): array
    {
        return [
            BrainArea::Semantic,
            BrainArea::Episodic,
            BrainArea::Encyclopedic,
            BrainArea::Procedural,
        ];
    }

    public function extractAll(MemorySource $source): array
    {
        $prompt = $this->loadPrompt();
        $schema = $this->loadSchema();
        $message = $this->buildMessage($source, $prompt);

        try {
            $result = $this->chatService->ask($message, [
                'structured_output' => $schema,
                'module' => 'brain',
                'action' => 'extract_multi_area',
            ]);
        } catch (\Throwable $e) {
            // On utilise Semantic comme aire "pivot" pour l'exception car
            // l'aire visée est multiple ; le message décrit le contexte
            throw new ExtractionFailedException($source, BrainArea::Semantic, 'multi-area ChatService call failed: '.$e->getMessage(), $e);
        }

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured)) {
            throw new ExtractionFailedException($source, BrainArea::Semantic, 'multi-area: missing or non-array structured_output');
        }

        $debug = [
            'model' => $result['model'] ?? 'unknown',
            'usage' => $result['usage'] ?? [],
        ];

        $results = [];

        $semantic = $this->buildSemanticResult($source, $structured, $debug);
        if (null !== $semantic) {
            $results[] = $semantic;
        }

        $episodic = $this->buildEpisodicResult($source, $structured, $debug);
        if (null !== $episodic) {
            $results[] = $episodic;
        }

        $encyclopedic = $this->buildEncyclopedicResult($structured, $debug);
        if (null !== $encyclopedic) {
            $results[] = $encyclopedic;
        }

        $procedural = $this->buildProceduralResult($source, $structured, $debug);
        if (null !== $procedural) {
            $results[] = $procedural;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $debug
     */
    private function buildSemanticResult(MemorySource $source, array $structured, array $debug): ?ExtractionResult
    {
        $section = $structured['semantic'] ?? null;
        if (!is_array($section) || !isset($section['facts']) || !is_array($section['facts'])) {
            return null;
        }

        $neurons = [];
        foreach ($section['facts'] as $fact) {
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
            $confidence = max(0.0, min(1.0, (float) $confidence));

            $neurons[] = new SemanticNeuron(
                firstSource: $source->getId(),
                subject: $subject,
                predicate: $predicate,
                value: $value,
                confidence: $confidence,
            );
        }

        return new ExtractionResult(
            area: BrainArea::Semantic,
            neurons: $neurons,
            debug: $debug + ['extracted_count' => count($neurons), 'raw_count' => count($section['facts'])],
        );
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $debug
     */
    private function buildEpisodicResult(MemorySource $source, array $structured, array $debug): ?ExtractionResult
    {
        $section = $structured['episodic'] ?? null;
        if (!is_array($section) || !array_key_exists('episode', $section)) {
            return null;
        }

        $episode = $section['episode'];
        if (null === $episode) {
            return new ExtractionResult(
                area: BrainArea::Episodic,
                neurons: [],
                debug: $debug + ['extracted_count' => 0, 'reason' => 'llm_returned_null'],
            );
        }
        if (!is_array($episode)) {
            return null;
        }

        $occurredAtRaw = $episode['occurred_at'] ?? null;
        $eventSummary = $episode['event_summary'] ?? null;
        $actorsRaw = $episode['actors'] ?? [];
        $location = $episode['location'] ?? null;

        if (!is_string($occurredAtRaw) || !is_string($eventSummary)) {
            return null;
        }

        try {
            $occurredAt = new \DateTimeImmutable($occurredAtRaw);
        } catch (\Exception) {
            return null;
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

    /**
     * **Note** : on ne crée PAS d'EncyclopedicNeuron ici. On expose le texte
     * que le LLM a identifié comme chunkable, et c'est au caller
     * (MemoryExtractor) de router vers EncyclopedicExtractor pour produire
     * les chunks effectifs (chunking + embedding).
     *
     * Retourne un ExtractionResult avec `neurons: []` et `debug.chunkable_text`
     * porteur du texte à chunker (peut être null si le LLM ne l'a pas vu
     * pertinent).
     *
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $debug
     */
    private function buildEncyclopedicResult(array $structured, array $debug): ?ExtractionResult
    {
        $section = $structured['encyclopedic'] ?? null;
        if (!is_array($section) || !array_key_exists('chunkable_text', $section)) {
            return null;
        }

        $chunkable = $section['chunkable_text'];
        if (null === $chunkable) {
            return new ExtractionResult(
                area: BrainArea::Encyclopedic,
                neurons: [],
                debug: $debug + ['extracted_count' => 0, 'reason' => 'llm_returned_null'],
            );
        }
        if (!is_string($chunkable) || '' === trim($chunkable)) {
            return null;
        }

        return new ExtractionResult(
            area: BrainArea::Encyclopedic,
            neurons: [],  // chunking délégué à EncyclopedicExtractor
            debug: $debug + [
                'extracted_count' => 0,
                'chunkable_text' => $chunkable,
                'note' => 'Chunking délégué — caller doit router vers EncyclopedicExtractor.',
            ],
        );
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $debug
     */
    private function buildProceduralResult(MemorySource $source, array $structured, array $debug): ?ExtractionResult
    {
        $section = $structured['procedural'] ?? null;
        if (!is_array($section) || !array_key_exists('procedure', $section)) {
            return null;
        }

        $procedure = $section['procedure'];
        if (null === $procedure) {
            return new ExtractionResult(
                area: BrainArea::Procedural,
                neurons: [],
                debug: $debug + ['extracted_count' => 0, 'reason' => 'llm_returned_null'],
            );
        }
        if (!is_array($procedure)) {
            return null;
        }

        $name = $procedure['name'] ?? null;
        $triggerPattern = $procedure['trigger_pattern'] ?? null;
        $steps = $procedure['steps'] ?? null;
        $conditions = $procedure['conditions'] ?? [];

        if (!is_string($name) || !is_array($triggerPattern) || !is_array($steps)) {
            return null;
        }
        if (!is_array($conditions)) {
            $conditions = [];
        }

        // Filtrer les steps : ne garder que les array (objets)
        $cleanSteps = [];
        foreach ($steps as $step) {
            if (is_array($step)) {
                /* @var array<string, mixed> $step */
                $cleanSteps[] = $step;
            }
        }

        $neuron = new ProceduralNeuron(
            source: $source,
            name: $name,
            triggerPattern: $triggerPattern,
            steps: $cleanSteps,
            conditions: $conditions,
        );

        return new ExtractionResult(
            area: BrainArea::Procedural,
            neurons: [$neuron],
            debug: $debug + ['extracted_count' => 1],
        );
    }

    private function loadPrompt(): string
    {
        $contents = @file_get_contents(self::PROMPT_PATH);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('OnePassMultiAreaExtractor: unable to read prompt at %s', self::PROMPT_PATH));
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
            throw new \RuntimeException(sprintf('OnePassMultiAreaExtractor: unable to read schema at %s', self::SCHEMA_PATH));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function buildMessage(MemorySource $source, string $prompt): string
    {
        $payload = json_encode($source->getRawPayload(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $prompt
            ."\n\n## Source à analyser\n\nProvider : ".$source->getProvider()
            ."\n\nReçue : ".$source->getReceivedAt()->format('c')
            ."\n\nPayload :\n```json\n".$payload."\n```\n";
    }
}
