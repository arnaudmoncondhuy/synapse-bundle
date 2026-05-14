<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Squelette commun pour les extracteurs basés sur un appel LLM avec
 * structured output (ADR-003).
 *
 * Factorise :
 * - Chargement du prompt depuis Resources/brain/prompts/<file>.md
 * - Chargement du JSON schema depuis Resources/brain/prompts/<file>.schema.json
 * - Appel à ChatService::ask avec structured_output
 * - Gestion uniforme des erreurs (ExtractionFailedException)
 * - Construction du message standardisée (prompt + provider + payload)
 *
 * Les sous-classes implémentent :
 * - {@see promptFileName()} : nom du fichier prompt (sans extension)
 * - {@see expectedArea()} : aire que l'extracteur supporte
 * - {@see chatAction()} : libellé court pour le token accounting
 * - {@see buildMessageContext()} : contexte additionnel à inclure dans le
 *   message (override de la méthode si l'extracteur a besoin d'infos
 *   spécifiques comme receivedAt)
 * - {@see buildNeurons()} : transforme le structured_output en list<MemoryFragment>
 */
abstract readonly class AbstractLlmExtractor implements NeuronExtractorInterface
{
    private const RESOURCES_DIR = __DIR__.'/../../../Resources/brain/prompts';

    public function __construct(
        protected ChatService $chatService,
    ) {
    }

    /**
     * @return list<BrainArea>
     */
    final public function supportedAreas(): array
    {
        return [$this->expectedArea()];
    }

    final public function extract(MemorySource $source, BrainArea $targetArea): ExtractionResult
    {
        if ($this->expectedArea() !== $targetArea) {
            throw new ExtractionFailedException($source, $targetArea, sprintf('%s does not support area "%s"', static::class, $targetArea->value));
        }

        $prompt = $this->loadPrompt();
        $schema = $this->loadSchema();
        $message = $this->buildMessage($source, $prompt);

        try {
            $result = $this->chatService->ask($message, [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'brain_'.$this->chatAction(),
                        'schema' => $schema,
                    ],
                ],
                'module' => 'brain',
                'action' => $this->chatAction(),
            ]);
        } catch (\Throwable $e) {
            throw new ExtractionFailedException($source, $targetArea, 'ChatService call failed: '.$e->getMessage(), $e);
        }

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured)) {
            throw new ExtractionFailedException($source, $targetArea, 'invalid structured output: missing or non-array structured_output');
        }

        [$neurons, $extraDebug] = $this->buildNeurons($source, $structured);

        return new ExtractionResult(
            area: $this->expectedArea(),
            neurons: $neurons,
            debug: array_merge(
                [
                    'model' => $result['model'] ?? 'unknown',
                    'usage' => $result['usage'] ?? [],
                    'extracted_count' => count($neurons),
                ],
                $extraDebug,
            ),
        );
    }

    /**
     * Nom du fichier prompt (sans extension), ex: 'extract-semantic'.
     * Le fichier `.md` est utilisé comme prompt, le `.schema.json` comme schema.
     */
    abstract protected function promptFileName(): string;

    /**
     * Aire unique supportée par cet extracteur.
     */
    abstract protected function expectedArea(): BrainArea;

    /**
     * Libellé court pour le champ `action` du token accounting, ex:
     * 'extract_semantic'. Module est toujours 'brain'.
     */
    abstract protected function chatAction(): string;

    /**
     * Transforme le payload structured_output renvoyé par le LLM en
     * neurones de l'aire visée.
     *
     * @param array<string, mixed> $structured
     *
     * @return array{0: list<\ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment>, 1: array<string, mixed>}
     *                                                                                                              tuple [neurones, extra_debug]
     */
    abstract protected function buildNeurons(MemorySource $source, array $structured): array;

    /**
     * Contexte additionnel à inclure dans le message LLM. Par défaut : vide.
     * Surchargeable par les sous-classes qui ont besoin d'infos contextuelles
     * (ex: EpisodicExtractor ajoute receivedAt pour résoudre les références
     * temporelles relatives).
     */
    protected function buildMessageContext(MemorySource $source): string
    {
        return '';
    }

    private function loadPrompt(): string
    {
        $path = self::RESOURCES_DIR.'/'.$this->promptFileName().'.md';
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('%s: unable to read prompt at %s', static::class, $path));
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(): array
    {
        $path = self::RESOURCES_DIR.'/'.$this->promptFileName().'.schema.json';
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('%s: unable to read schema at %s', static::class, $path));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function buildMessage(MemorySource $source, string $prompt): string
    {
        $payload = json_encode($source->getRawPayload(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        $message = $prompt
            ."\n\n## Source à analyser\n\nProvider : ".$source->getProvider();

        $extra = $this->buildMessageContext($source);
        if ('' !== $extra) {
            $message .= "\n\n".$extra;
        }

        $message .= "\n\nPayload :\n```json\n".$payload."\n```\n";

        return $message;
    }
}
