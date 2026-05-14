<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Event;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\MemoryRetrieverInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\PromptUtil;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Injecte les résultats du retrieval Brain (jalon 4) dans le contexte LLM
 * en phase ENRICH du `PromptPipeline`.
 *
 * Priorité 45 — s'exécute **entre** `MemoryContextSubscriber` (priorité 50)
 * et `RagContextSubscriber` (priorité 40) pour cohabiter pendant la phase
 * de transition. À terme (jalon 5+), Brain absorbera ces deux subscribers
 * (cf. plan jalon 4 §4.8).
 *
 * Comportement :
 * 1. Récupère l'`ownerId` de l'utilisateur courant (TokenStorage ou option `user_id`)
 * 2. Lance `MemoryRetriever::retrieve()` sur le message utilisateur
 * 3. Injecte les top-N neurones en markdown dans le system message
 * 4. Stocke un résumé pour audit dans `prompt[metadata][brain_retrieval]`
 *
 * **Isolation user** : le retrieval reçoit le `ownerId` du user. Si aucun
 * user identifié (anonyme, batch), le retrieval tombe sur la couche
 * open uniquement (cf. ADR-006 + audit isolation-paranoid).
 *
 * **Stateless** : si `options['stateless'] === true` (ex: title generation),
 * on saute l'enrichment Brain pour économiser un retrieval inutile.
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.8.
 */
final class BrainContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MemoryRetrieverInterface $retriever,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly int $topN = 10,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PromptEnrichEvent::class => ['onEnrich', 45],
        ];
    }

    public function onEnrich(PromptEnrichEvent $event): void
    {
        $options = $event->getOptions();

        if (!empty($options['stateless'])) {
            return;
        }

        $message = $event->getMessage();
        if ('' === trim($message)) {
            return;
        }

        $ownerId = $this->resolveOwnerId($options);

        try {
            $result = $this->retriever->retrieve(new RetrievalQuery(
                text: $message,
                ownerId: $ownerId,
                topN: $this->topN,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('BrainContextSubscriber: retrieval failed, skipping enrichment', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $prompt = $event->getPrompt();
        $metadata = is_array($prompt['metadata'] ?? null) ? $prompt['metadata'] : [];

        $metadata['brain_retrieval'] = [
            'found' => $result->count(),
            'debug' => $result->debug,
        ];
        $prompt['metadata'] = $metadata;

        if ($result->isEmpty()) {
            $event->setPrompt($prompt);

            return;
        }

        $brainBlock = $this->buildBrainBlock($result->neurons);
        $contentsRaw = $prompt['contents'] ?? [];
        $messages = is_array($contentsRaw) ? $contentsRaw : [];
        $prompt['contents'] = PromptUtil::appendToSystemMessage($messages, $brainBlock);

        $event->setPrompt($prompt);
    }

    /**
     * Récupère l'ownerId depuis `options['user_id']` (priorité) ou TokenStorage.
     *
     * Le `user_id` peut être un string (compat MemoryContextSubscriber) ou
     * directement un Uuid. On normalise vers `?Uuid`.
     *
     * @param array<string, mixed> $options
     */
    private function resolveOwnerId(array $options): ?Uuid
    {
        $raw = $options['user_id'] ?? null;
        if ($raw instanceof Uuid) {
            return $raw;
        }
        if (is_string($raw) && '' !== $raw) {
            try {
                return Uuid::fromString($raw);
            } catch (\InvalidArgumentException) {
                $this->logger->debug('BrainContextSubscriber: user_id is not a valid UUID, falling back', [
                    'user_id' => $raw,
                ]);
            }
        }

        if (null === $this->tokenStorage) {
            return null;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return null;
        }

        $id = $user->getId();
        if (null === $id) {
            return null;
        }

        try {
            return Uuid::fromString((string) $id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Construit le bloc markdown à injecter dans le system prompt.
     *
     * @param list<ScoredNeuron> $neurons
     */
    private function buildBrainBlock(array $neurons): string
    {
        $lines = ["\n\n---\n\n### 🧠 Mémoire associative (Brain v3)\n"];
        $lines[] = "Les neurones suivants ont été activés par votre demande :\n";

        foreach ($neurons as $scored) {
            $excerpt = $this->extractExcerpt($scored->neuron);
            $lines[] = sprintf(
                '- [%s, score %.2f, depth %d] %s',
                $scored->neuron->getArea()->value,
                $scored->score,
                $scored->depth,
                $excerpt,
            );
        }

        $lines[] = "\nInstruction : utilise ces informations de manière naturelle si elles sont pertinentes pour répondre. Ne mentionne pas explicitement \"d'après mes souvenirs\".";

        return implode("\n", $lines);
    }

    private function extractExcerpt(MemoryFragment $neuron): string
    {
        $text = match (true) {
            $neuron instanceof SemanticNeuron => sprintf('%s %s %s', $neuron->getSubject(), $neuron->getPredicate(), $neuron->getValue()),
            $neuron instanceof EpisodicNeuron => $neuron->getEventSummary(),
            $neuron instanceof EncyclopedicNeuron => $neuron->getChunkContent(),
            $neuron instanceof ProceduralNeuron => $neuron->getName(),
            default => '(neurone non descriptible)',
        };
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        if (mb_strlen($text) > 120) {
            $text = mb_substr($text, 0, 117).'…';
        }

        return $text;
    }
}
