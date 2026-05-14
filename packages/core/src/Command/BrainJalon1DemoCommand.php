<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Démo bout en bout du jalon 1 — Fondations Brain v3.
 *
 * Crée :
 * 1. Une MemorySource (provider='manual')
 * 2. Un EpisodicNeuron lié à cette source
 * 3. Un SemanticNeuron lié à la même source
 * 4. Une Synapse entre les deux neurones (relation `corroborates`)
 *
 * Puis affiche les 4 entités persistées au format JSON.
 *
 * Sert de **test de sortie** du jalon 1 (cf. plan jalon-1, §2). Si cette
 * commande tourne sans erreur sur une app hôte fraîchement migrée, le
 * jalon 1 est livré.
 *
 * Ref: docs/brain/06-phases/jalon-1-fondations.md §2
 */
#[AsCommand(
    name: 'brain:demo:jalon-1',
    description: 'Démo bout en bout du jalon 1 Brain v3 (MemorySource + 2 neurones + 1 synapse).',
)]
final class BrainJalon1DemoCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Brain v3 — Démo Jalon 1 (Fondations)');

        // 1. MemorySource
        $source = new MemorySource(
            provider: 'manual',
            rawPayload: [
                'note' => 'Démo Brain v3 — jalon 1',
                'context' => 'demo',
                'created_at' => (new \DateTimeImmutable())->format('c'),
            ],
            externalId: 'demo-jalon-1',
        );
        $this->em->persist($source);

        // 2. EpisodicNeuron
        $episodic = new EpisodicNeuron(
            source: $source,
            occurredAt: new \DateTimeImmutable(),
            eventSummary: 'Première démo Brain v3 lancée en CLI',
            actors: ['cli-user'],
            location: 'localhost',
        );
        $this->em->persist($episodic);

        // 3. SemanticNeuron
        $semantic = new SemanticNeuron(
            firstSource: $source->getId(),
            subject: 'Brain v3',
            predicate: 'is_at',
            value: 'jalon 1 (fondations)',
            confidence: 0.9,
        );
        $this->em->persist($semantic);

        // 4. Synapse entre les deux neurones (l'épisode corrobore le fait)
        // Note : sourceOwnerId/targetOwnerId omis (defaults null/null = open/open) — intentionnel pour la démo jalon 1
        $synapse = new Synapse(
            source: $episodic,
            target: $semantic,
            weight: 0.5,
            relationType: SynapseRelationType::Corroborates,
        );
        $this->em->persist($synapse);

        $this->em->flush();

        // ── Rendu
        $io->section('1. MemorySource créée');
        $io->writeln(json_encode([
            'id' => $source->getId()->toRfc4122(),
            'provider' => $source->getProvider(),
            'externalId' => $source->getExternalId(),
            'receivedAt' => $source->getReceivedAt()->format('c'),
            'payload' => $source->getRawPayload(),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        $io->section('2. EpisodicNeuron créé (aire '.$episodic->getArea()->value.')');
        $io->writeln(json_encode([
            'id' => $episodic->getId()->toRfc4122(),
            'sourceUuid' => $episodic->getSourceUuid()?->toRfc4122(),
            'occurredAt' => $episodic->getOccurredAt()->format('c'),
            'location' => $episodic->getLocation(),
            'actors' => $episodic->getActors(),
            'eventSummary' => $episodic->getEventSummary(),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        $io->section('3. SemanticNeuron créé (aire '.$semantic->getArea()->value.')');
        $io->writeln(json_encode([
            'id' => $semantic->getId()->toRfc4122(),
            'sourceUuid' => $semantic->getSourceUuid()?->toRfc4122(),
            'subject' => $semantic->getSubject(),
            'predicate' => $semantic->getPredicate(),
            'value' => $semantic->getValue(),
            'confidence' => $semantic->getConfidence(),
            'evidenceCount' => $semantic->getEvidenceCount(),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        $io->section('4. Synapse créée');
        $io->writeln(json_encode([
            'id' => $synapse->getId()->toRfc4122(),
            'source' => [
                'area' => $synapse->getSourceNeuronArea()->value,
                'id' => $synapse->getSourceNeuronId()->toRfc4122(),
            ],
            'target' => [
                'area' => $synapse->getTargetNeuronArea()->value,
                'id' => $synapse->getTargetNeuronId()->toRfc4122(),
            ],
            'weight' => $synapse->getWeight(),
            'polarity' => $synapse->getPolarity()->value,
            'relationType' => $synapse->getRelationType()->value,
            'confidence' => $synapse->getConfidence(),
            'evidenceCount' => $synapse->getEvidenceCount(),
            'edgeType' => $synapse->getEdgeType()->value,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        $io->success('Démo jalon 1 OK — 4 lignes persistées en BDD.');

        return Command::SUCCESS;
    }
}
