<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;

/**
 * Sérialiseur de neurones vers un payload array<string, mixed> sérialisable
 * en JSON, par aire.
 *
 * Utilisé par les commands de debug / inspection (BrainIngestTestCommand,
 * brain:demo:*). Remplace l'ancienne sérialisation par reflection
 * (audit code reviewer point f).
 *
 * Pattern visiteur typé : un `match` sur la classe concrète garantit qu'on
 * ajoute une branche dès qu'on introduit une nouvelle entité de neurone.
 * Si on oublie une aire au jalon 3 (Procedural, Emotional, Sensory, Motor),
 * PHP lève `UnhandledMatchError` au runtime — moins silencieux que les
 * `method_exists` qui ratent une nouvelle propriété sans broncher.
 */
final readonly class MemoryFragmentSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(MemoryFragment $neuron): array
    {
        $common = [
            'class' => $neuron::class,
            'id' => $neuron->getId()->toRfc4122(),
            'area' => $neuron->getArea()->value,
            'sourceUuid' => $neuron->getSourceUuid()?->toRfc4122(),
        ];

        return array_merge($common, match (true) {
            $neuron instanceof SemanticNeuron => $this->serializeSemantic($neuron),
            $neuron instanceof EpisodicNeuron => $this->serializeEpisodic($neuron),
            $neuron instanceof EncyclopedicNeuron => $this->serializeEncyclopedic($neuron),
            default => ['_warning' => sprintf('No serializer for class %s', $neuron::class)],
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSemantic(SemanticNeuron $n): array
    {
        return [
            'subject' => $n->getSubject(),
            'predicate' => $n->getPredicate(),
            'value' => $n->getValue(),
            'confidence' => $n->getConfidence(),
            'evidenceCount' => $n->getEvidenceCount(),
            'lastCorroboratedAt' => $n->getLastCorroboratedAt()->format('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEpisodic(EpisodicNeuron $n): array
    {
        return [
            'occurredAt' => $n->getOccurredAt()->format('c'),
            'location' => $n->getLocation(),
            'actors' => $n->getActors(),
            'eventSummary' => $n->getEventSummary(),
            'sequenceId' => $n->getSequenceId()?->toRfc4122(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEncyclopedic(EncyclopedicNeuron $n): array
    {
        return [
            'documentRef' => $n->getDocumentRef(),
            'chunkIndex' => $n->getChunkIndex(),
            'totalChunks' => $n->getTotalChunks(),
            // chunkContent tronqué pour les commands de debug — la version
            // complète est en BDD si --persist est utilisé
            'chunkContent' => $this->truncate($n->getChunkContent(), 200),
            'docMetadata' => $n->getDocMetadata(),
        ];
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
