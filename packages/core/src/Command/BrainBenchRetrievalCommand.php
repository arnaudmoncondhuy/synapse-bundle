<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\MemoryRetrieverInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
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
 * Bench du retrieval Brain v3 sur les fixtures annotées (jalon 4 étape 11).
 *
 * Lit un fichier `queries.json` contenant N queries NL avec leurs sources
 * attendues (annotées en aveugle), lance `MemoryRetriever::retrieve()` pour
 * chacune, et calcule recall@K, precision@K, F1@K en mappant chaque neurone
 * retourné vers sa MemorySource d'origine.
 *
 * Sortie :
 * - Tableau par query (recall, precision, F1 à différents K)
 * - Métriques globales (moyennes)
 * - Distribution des depths des hits
 * - Coût LLM total lu depuis `synapse_llm_call` (module=brain)
 * - Sortie JSON dans `--out` pour comparaison cross-config
 *
 * Mode comparaison : `--baseline=<file.json>` charge un run précédent
 * et affiche les deltas (utile pour A/B sur hyperparamètres).
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §6 et ADR-010/011.
 */
#[AsCommand(
    name: 'brain:bench:retrieval',
    description: 'Bench du retrieval Brain sur fixtures annotées (recall, precision, F1, coût LLM).',
)]
final class BrainBenchRetrievalCommand extends Command
{
    private const DEFAULT_K_VALUES = [3, 5, 10];

    public function __construct(
        private readonly MemoryRetrieverInterface $retriever,
        private readonly MemorySourceRepository $sourceRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fixtures', null, InputOption::VALUE_REQUIRED, 'Path vers queries.json', 'packages/core/tests/Brain/Quality/Fixtures/retrieval-v1/queries.json')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'UUID owner (vide = couche open)')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'topN par query', '10')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Path de sortie JSON pour comparaison')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Path JSON d\'un run précédent à comparer')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Étiquette de ce run (ex: "default", "decay-0.5")', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fixturesPath = (string) $input->getOption('fixtures');
        $ownerOpt = $input->getOption('owner');
        $owner = is_string($ownerOpt) && '' !== $ownerOpt ? Uuid::fromString($ownerOpt) : null;
        $topN = (int) $input->getOption('top');
        $outPath = $input->getOption('out');
        $baselinePath = $input->getOption('baseline');
        $label = (string) $input->getOption('label');

        if (!is_file($fixturesPath)) {
            $io->error(sprintf('Fixtures introuvables : %s', $fixturesPath));

            return Command::FAILURE;
        }

        /** @var array{meta: array<string, mixed>, queries: list<array{id: string, text: string, expected_sources: list<string>, difficulty?: string, rationale?: string}>} $fixtures */
        $fixtures = json_decode((string) file_get_contents($fixturesPath), true, 512, JSON_THROW_ON_ERROR);

        $io->title(sprintf('Brain bench retrieval — %s', $label));
        $io->text(sprintf('Fixtures : %s', $fixturesPath));
        $io->text(sprintf('Queries : %d', count($fixtures['queries'])));
        $io->text(sprintf('topN : %d, ownerId : %s', $topN, null === $owner ? 'null (open)' : $owner->toRfc4122()));

        $startedAt = new \DateTimeImmutable();

        $results = [];
        $globalMetrics = ['recall' => [], 'precision' => [], 'f1' => []];
        foreach (self::DEFAULT_K_VALUES as $k) {
            $globalMetrics['recall'][$k] = [];
            $globalMetrics['precision'][$k] = [];
            $globalMetrics['f1'][$k] = [];
        }

        foreach ($fixtures['queries'] as $q) {
            $io->section(sprintf('Query %s : "%s"', $q['id'], $q['text']));

            $expected = $q['expected_sources'];
            $expectedIds = array_flip($expected);

            $result = $this->retriever->retrieve(new RetrievalQuery(
                text: $q['text'],
                ownerId: $owner,
                topN: $topN,
                minScore: 0.0, // Pas de filtrage minScore en bench (on veut tout voir)
            ));

            // Map neuron → sourceUuid → source.externalId
            $retrievedExternalIds = [];
            $retrievedRows = [];
            foreach ($result->neurons as $scored) {
                $sourceUuid = $scored->neuron->getSourceUuid();
                $source = null !== $sourceUuid ? $this->sourceRepo->find($sourceUuid) : null;
                $externalId = $source instanceof MemorySource ? $source->getExternalId() : null;
                $retrievedExternalIds[] = $externalId;
                $retrievedRows[] = [
                    'score' => sprintf('%.3f', $scored->score),
                    'area' => $scored->neuron->getArea()->value,
                    'depth' => $scored->depth,
                    'external_id' => $externalId ?? '—',
                    'relevant' => null !== $externalId && isset($expectedIds[$externalId]) ? '✓' : '✗',
                ];
            }

            $io->table(['Score', 'Aire', 'Depth', 'Source', 'Pertinent'], $retrievedRows);

            $perKMetrics = [];
            foreach (self::DEFAULT_K_VALUES as $k) {
                $topK = array_slice($retrievedExternalIds, 0, $k);
                $hits = count(array_filter($topK, static fn ($id) => null !== $id && isset($expectedIds[$id])));
                $recall = [] === $expected ? 0.0 : $hits / count($expected);
                $precision = $hits / $k;
                $f1 = (0.0 + $recall + $precision) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0.0;
                $perKMetrics[$k] = ['recall' => $recall, 'precision' => $precision, 'f1' => $f1];
                $globalMetrics['recall'][$k][] = $recall;
                $globalMetrics['precision'][$k][] = $precision;
                $globalMetrics['f1'][$k][] = $f1;
            }

            $io->writeln(sprintf(
                'Recall: @3=%.2f @5=%.2f @10=%.2f | Precision: @3=%.2f @5=%.2f @10=%.2f | F1: @3=%.2f @5=%.2f @10=%.2f',
                $perKMetrics[3]['recall'], $perKMetrics[5]['recall'], $perKMetrics[10]['recall'],
                $perKMetrics[3]['precision'], $perKMetrics[5]['precision'], $perKMetrics[10]['precision'],
                $perKMetrics[3]['f1'], $perKMetrics[5]['f1'], $perKMetrics[10]['f1'],
            ));

            $results[$q['id']] = [
                'text' => $q['text'],
                'expected' => $expected,
                'retrieved' => $retrievedExternalIds,
                'metrics' => $perKMetrics,
                'debug' => $result->debug,
            ];
        }

        // Métriques globales
        $io->section('Métriques globales (moyennes)');
        $globalRows = [];
        foreach (self::DEFAULT_K_VALUES as $k) {
            $globalRows[] = [
                "@{$k}",
                sprintf('%.3f', $this->mean($globalMetrics['recall'][$k])),
                sprintf('%.3f', $this->mean($globalMetrics['precision'][$k])),
                sprintf('%.3f', $this->mean($globalMetrics['f1'][$k])),
            ];
        }
        $io->table(['K', 'Recall moyen', 'Precision moyenne', 'F1 moyen'], $globalRows);

        // Coût LLM total
        $cost = $this->aggregateCost($startedAt);
        $io->section('Coût LLM total');
        $io->writeln(sprintf('%d appels LLM (module=brain) — coût total : %.6f', $cost['count'], $cost['total']));
        $io->writeln(sprintf('Moyenne par query : %.6f', count($fixtures['queries']) > 0 ? $cost['total'] / count($fixtures['queries']) : 0.0));

        // Comparaison baseline
        if (is_string($baselinePath) && is_file($baselinePath)) {
            $this->renderBaselineDelta($io, $baselinePath, $globalMetrics);
        }

        // Sortie JSON
        if (is_string($outPath) && '' !== $outPath) {
            $output = [
                'label' => $label,
                'fixtures' => $fixturesPath,
                'topN' => $topN,
                'owner' => $owner?->toRfc4122(),
                'run_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'global_metrics' => array_combine(
                    self::DEFAULT_K_VALUES,
                    array_map(fn ($k) => [
                        'recall' => $this->mean($globalMetrics['recall'][$k]),
                        'precision' => $this->mean($globalMetrics['precision'][$k]),
                        'f1' => $this->mean($globalMetrics['f1'][$k]),
                    ], self::DEFAULT_K_VALUES),
                ),
                'cost' => $cost,
                'per_query' => $results,
            ];
            file_put_contents($outPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $io->success(sprintf('Résultats écrits dans %s', $outPath));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        return array_sum($values) / count($values);
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

    /**
     * @param array<string, array<int, list<float>>> $globalMetrics
     */
    private function renderBaselineDelta(SymfonyStyle $io, string $baselinePath, array $globalMetrics): void
    {
        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        if (!is_array($baseline)) {
            return;
        }
        $io->section(sprintf('Delta vs baseline (%s)', $baseline['label'] ?? '?'));
        $rows = [];
        foreach (self::DEFAULT_K_VALUES as $k) {
            $baseF1 = $baseline['global_metrics'][$k]['f1'] ?? 0.0;
            $thisF1 = $this->mean($globalMetrics['f1'][$k]);
            $delta = $thisF1 - $baseF1;
            $rows[] = ["@{$k}", sprintf('%.3f', $baseF1), sprintf('%.3f', $thisF1), sprintf('%+.3f', $delta)];
        }
        $io->table(['K', 'F1 baseline', 'F1 actuel', 'Δ F1'], $rows);
    }
}
