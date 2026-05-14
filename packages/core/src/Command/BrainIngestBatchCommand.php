<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\EmbeddableNeuron;
use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\ConvergenceCandidate;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\ConvergenceDetector;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\MemoryExtractor;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseEdgeType;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\MemorySourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Ingestion batch d'un corpus JSONL (1 ligne = 1 MemorySource avec liens
 * `raw_payload.related`) — utilisé pour amorcer le bench retrieval-v1.
 *
 * Pipeline pour chaque ligne :
 * 1. Crée la `MemorySource` (sauf si `external_id` déjà existant — idempotence)
 * 2. Lance `MemoryExtractor::extractAll` (multi-aires en 1 passe LLM)
 * 3. Génère l'embedding pour chaque neurone embeddable
 * 4. Détecte la convergence avec les neurones existants (même aire + cosine ≥ ADR-005)
 *
 * Puis, après ingestion de toutes les sources :
 *
 * 5. Pour chaque source, lit `raw_payload.related` (deal/person/organization/...)
 *    et crée une **synapse Transduction `Composes`** entre les neurones
 *    qui représentent ces sources. Câblage fixe (ADR-012 : pas de
 *    plasticité).
 *
 * **Coût** : 1 appel LLM par source (extraction multi-aires) + N embeddings
 * (1 par neurone embeddable). Tracé via `synapse_llm_call` (module=brain).
 *
 * **Idempotence** : si `external_id` existe déjà → skip extraction.
 *
 * Cf. plan jalon 4 étape 11.
 */
#[AsCommand(
    name: 'brain:ingest:batch',
    description: 'Ingère un corpus JSONL relationnel + crée les synapses Transduction Composes (jalon 4 bench).',
)]
final class BrainIngestBatchCommand extends Command
{
    public function __construct(
        private readonly MemoryExtractor $memoryExtractor,
        private readonly EmbeddingService $embeddingService,
        private readonly ConvergenceDetector $convergenceDetector,
        private readonly MemorySourceRepository $sourceRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('jsonl', null, InputOption::VALUE_REQUIRED, 'Path vers le corpus JSONL')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'UUID owner à appliquer à toutes les sources (défaut: null = open)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\'écrit pas en BDD, montre seulement le plan')
            ->addOption('skip-relational', null, InputOption::VALUE_NONE, 'Saute la création des synapses Transduction (ne fait que l\'extraction)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jsonlPath = (string) $input->getOption('jsonl');
        $ownerOpt = $input->getOption('owner');
        $owner = is_string($ownerOpt) && '' !== $ownerOpt ? Uuid::fromString($ownerOpt) : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $skipRelational = (bool) $input->getOption('skip-relational');

        if (!is_file($jsonlPath)) {
            $io->error("JSONL introuvable : {$jsonlPath}");

            return Command::FAILURE;
        }

        $startedAt = new \DateTimeImmutable();

        $io->title('Brain ingest batch');
        $io->text("JSONL : {$jsonlPath}");
        $io->text('Owner : '.(null === $owner ? 'null (open)' : $owner->toRfc4122()));
        $io->text('Dry-run : '.($dryRun ? 'oui' : 'non'));
        $io->text('Skip relational : '.($skipRelational ? 'oui' : 'non'));

        // ─── 1. Charger le JSONL ────────────────────────────────────────────
        $lines = file($jsonlPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines || [] === $lines) {
            $io->error('JSONL vide ou illisible');

            return Command::FAILURE;
        }

        $io->text(sprintf('Lignes JSONL : %d', count($lines)));

        // ─── 2. Phase A : créer MemorySource + extraire neurones ──────────
        $io->section('Phase A — extraction neurones (1 passe LLM par source)');

        /** @var array<string, MemorySource> $sourcesByExt */
        $sourcesByExt = [];
        /** @var array<string, list<MemoryFragment>> $neuronsByExt */
        $neuronsByExt = [];
        $stats = ['created' => 0, 'skipped_existing' => 0, 'extraction_errors' => 0, 'neurons_total' => 0];

        $progressBar = $io->createProgressBar(count($lines));
        $progressBar->start();

        foreach ($lines as $line) {
            /** @var array{provider: string, external_id: string, raw_payload: array<string, mixed>} $row */
            $row = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            $extId = $row['external_id'];
            $existing = $this->sourceRepo->findOneBy(['externalId' => $extId]);

            if (null !== $existing) {
                ++$stats['skipped_existing'];
                $sourcesByExt[$extId] = $existing;
                $progressBar->advance();
                continue;
            }

            $source = new MemorySource(
                provider: $row['provider'],
                rawPayload: $row['raw_payload'],
                externalId: $extId,
                ownerId: $owner,
            );

            if (!$dryRun) {
                $this->em->persist($source);
                $this->em->flush();
            }
            $sourcesByExt[$extId] = $source;
            ++$stats['created'];

            // Extraction
            try {
                $results = $this->memoryExtractor->extractAll($source);
                $extractedNeurons = [];
                foreach ($results as $result) {
                    foreach ($result->neurons as $n) {
                        if (!$dryRun) {
                            $this->em->persist($n);
                        }
                        $extractedNeurons[] = $n;
                        ++$stats['neurons_total'];
                    }
                }
                $neuronsByExt[$extId] = $extractedNeurons;
                if (!$dryRun) {
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                ++$stats['extraction_errors'];
                $io->newLine();
                $io->warning("Extraction échouée pour {$extId} : {$e->getMessage()}");
            }

            $progressBar->advance();
        }
        $progressBar->finish();
        $io->newLine(2);

        $io->table(['Métrique', 'Valeur'], [
            ['MemorySource créées', $stats['created']],
            ['MemorySource skippées (existantes)', $stats['skipped_existing']],
            ['Neurones extraits', $stats['neurons_total']],
            ['Erreurs extraction', $stats['extraction_errors']],
        ]);

        // ─── 3. Phase B : embeddings + convergence ─────────────────────────
        $io->section('Phase B — embeddings + détection de convergence');

        $embeddedCount = 0;
        $convergenceSynapsesCount = 0;
        /** @var list<EmbeddableNeuron> $allEmbeddableNeurons */
        $allEmbeddableNeurons = [];

        foreach ($neuronsByExt as $extId => $neurons) {
            foreach ($neurons as $neuron) {
                if (!$neuron instanceof EmbeddableNeuron) {
                    continue;
                }
                if ([] !== $neuron->getEmbedding()) {
                    $allEmbeddableNeurons[] = $neuron;
                    continue;
                }
                $textForEmbedding = $this->extractTextForEmbedding($neuron);
                if ('' === $textForEmbedding) {
                    continue;
                }

                $embResult = $this->embeddingService->generateEmbeddings(
                    $textForEmbedding,
                    null,
                    'brain_encyclopedic', // Mappé brain/encyclopedic_indexation
                );
                /** @var list<float> $vec */
                $vec = array_values($embResult['embeddings'][0] ?? []);
                if ([] !== $vec) {
                    $neuron->setEmbedding($vec);
                    ++$embeddedCount;
                    $allEmbeddableNeurons[] = $neuron;
                }
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }

        // Convergence : pour chaque nouveau neurone, comparer aux autres de même aire
        // Note : ConvergenceCandidate prend (neuron, ownerId)
        foreach ($neuronsByExt as $extId => $neurons) {
            foreach ($neurons as $candidate) {
                if (!$candidate instanceof EmbeddableNeuron) {
                    continue;
                }
                if ([] === $candidate->getEmbedding()) {
                    continue;
                }
                /** @var list<ConvergenceCandidate> $existing */
                $existing = [];
                foreach ($allEmbeddableNeurons as $other) {
                    if ($other === $candidate) {
                        continue;
                    }
                    $existing[] = new ConvergenceCandidate($other, $owner);
                }
                $synapses = $this->convergenceDetector->detectAndLink($candidate, $owner, $existing);
                foreach ($synapses as $syn) {
                    if (!$dryRun) {
                        $this->em->persist($syn);
                    }
                    ++$convergenceSynapsesCount;
                }
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(['Métrique', 'Valeur'], [
            ['Embeddings générés', $embeddedCount],
            ['Synapses convergence créées', $convergenceSynapsesCount],
        ]);

        // ─── 4. Phase C : synapses Transduction Composes ──────────────────
        $transductionCount = 0;
        if (!$skipRelational) {
            $io->section('Phase C — synapses Transduction `Composes` (liens structurels métier)');

            foreach ($lines as $line) {
                /** @var array{external_id: string, raw_payload: array<string, mixed>} $row */
                $row = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                $extId = $row['external_id'];
                $related = $row['raw_payload']['related'] ?? null;
                if (!is_array($related) || [] === $related) {
                    continue;
                }

                $sourceNeurons = $neuronsByExt[$extId] ?? [];
                if ([] === $sourceNeurons) {
                    continue;
                }
                $sourceFirstNeuron = $sourceNeurons[0]; // 1 neurone "principal" par source

                foreach ($related as $relatedExtId) {
                    if (!is_string($relatedExtId) || !isset($neuronsByExt[$relatedExtId])) {
                        continue;
                    }
                    $targetNeurons = $neuronsByExt[$relatedExtId];
                    if ([] === $targetNeurons) {
                        continue;
                    }
                    $targetFirstNeuron = $targetNeurons[0];

                    try {
                        $synapse = new Synapse(
                            source: $sourceFirstNeuron,
                            target: $targetFirstNeuron,
                            sourceOwnerId: $owner,
                            targetOwnerId: $owner,
                            weight: 1.0,
                            relationType: SynapseRelationType::Composes,
                            confidence: 1.0,
                            evidenceCount: 1,
                            edgeType: SynapseEdgeType::Transduction,
                        );
                        if (!$dryRun) {
                            $this->em->persist($synapse);
                        }
                        ++$transductionCount;
                    } catch (\Throwable $e) {
                        $io->warning("Synapse {$extId} → {$relatedExtId} skippée : {$e->getMessage()}");
                    }
                }
            }
            if (!$dryRun) {
                $this->em->flush();
            }
            $io->writeln(sprintf('Synapses Transduction Composes créées : %d', $transductionCount));
        }

        // ─── 5. Coût LLM total ─────────────────────────────────────────────
        $cost = $this->aggregateCost($startedAt);
        $io->section('Coût LLM total (synapse_llm_call)');
        $io->writeln(sprintf('%d appels — %.6f', $cost['count'], $cost['total']));

        if ($dryRun) {
            $io->warning('Mode dry-run : rien persisté en BDD.');
        } else {
            $io->success(sprintf(
                'Ingestion terminée. %d sources, %d neurones, %d synapses convergence, %d synapses transduction.',
                $stats['created'],
                $stats['neurons_total'],
                $convergenceSynapsesCount,
                $transductionCount,
            ));
        }

        return Command::SUCCESS;
    }

    private function extractTextForEmbedding(MemoryFragment $neuron): string
    {
        // Délègue au getter d'extrait selon le type
        if ($neuron instanceof \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron) {
            return sprintf('%s %s %s', $neuron->getSubject(), $neuron->getPredicate(), $neuron->getValue());
        }
        if ($neuron instanceof \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron) {
            return $neuron->getEventSummary();
        }
        if ($neuron instanceof \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron) {
            return $neuron->getChunkContent();
        }

        return '';
    }

    /**
     * @return array{count: int, total: float}
     */
    private function aggregateCost(\DateTimeImmutable $since): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(SynapseLlmCall::class, 'c')
            ->where('c.createdAt >= :since')
            ->andWhere('c.module = :module')
            ->setParameter('since', $since)
            ->setParameter('module', 'brain');

        /** @var list<SynapseLlmCall> $calls */
        $calls = $qb->getQuery()->getResult();

        $total = 0.0;
        foreach ($calls as $c) {
            $total += (float) ($c->getCostReference() ?? 0.0);
        }

        return ['count' => count($calls), 'total' => $total];
    }
}
