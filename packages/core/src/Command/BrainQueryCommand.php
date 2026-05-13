<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\MemoryRetriever;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Test de sortie jalon 4 — retrieval Brain.
 *
 * Prend une requête en langage naturel et orchestre :
 * SeedExtractor → SpreadingActivation → re-rank embedding → HebbianReinforcer.
 *
 * Affiche :
 * - Top-N neurones avec score, aire, depth, synapse traversée, extrait textuel
 * - Bloc debug (timing par étape, counts seeds/spread/reranked, model embedding)
 * - **Bloc coût** : lit `synapse_llm_call` après l'appel pour donner le coût
 *   exact € de chaque appel LLM et le total pour cette query
 *
 * Cf. docs/brain/06-phases/jalon-4-retrieval-hebbien.md §4.7
 */
#[AsCommand(
    name: 'brain:query',
    description: 'Lance un retrieval Brain et affiche le top-N + coût LLM exact (test de sortie jalon 4).',
)]
final class BrainQueryCommand extends Command
{
    public function __construct(
        private readonly MemoryRetriever $memoryRetriever,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Requête en langage naturel')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'UUID owner (vide = couche open)')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Nombre max de résultats', '10')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'maxDepth BFS', '3')
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'minScore (en dessous = bruit)', '0.1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $text = (string) $input->getArgument('query');
        $ownerOpt = $input->getOption('owner');
        $owner = is_string($ownerOpt) && '' !== $ownerOpt ? Uuid::fromString($ownerOpt) : null;
        $topN = (int) $input->getOption('top');
        $maxDepth = (int) $input->getOption('depth');
        $minScore = (float) $input->getOption('min-score');

        $io->title('Brain retrieval');
        $io->text(sprintf('Requête : "%s"', $text));
        $io->text(sprintf(
            'Paramètres : ownerId=%s topN=%d maxDepth=%d minScore=%.2f',
            null === $owner ? 'null (open)' : $owner->toRfc4122(),
            $topN,
            $maxDepth,
            $minScore,
        ));

        $startedAt = new \DateTimeImmutable();

        $query = new RetrievalQuery(
            text: $text,
            ownerId: $owner,
            topN: $topN,
            maxDepth: $maxDepth,
            minScore: $minScore,
        );

        $result = $this->memoryRetriever->retrieve($query);

        // Résultats
        if ($result->isEmpty()) {
            $io->warning(sprintf('Aucun neurone retourné. Raison : %s', $result->debug['reason'] ?? 'inconnue'));
        } else {
            $rows = [];
            foreach ($result->neurons as $i => $scored) {
                $rows[] = [
                    $i + 1,
                    sprintf('%.4f', $scored->score),
                    $scored->neuron->getArea()->value,
                    $scored->depth,
                    $this->snippet($scored),
                    null === $scored->reachedVia ? '—' : substr($scored->reachedVia->toRfc4122(), 0, 8).'…',
                ];
            }
            $io->table(['#', 'Score', 'Aire', 'Depth', 'Extrait', 'Synapse'], $rows);
        }

        // Debug
        $io->section('Debug retrieval');
        $debug = $result->debug;
        foreach ($debug as $key => $val) {
            if (is_array($val)) {
                $io->text(sprintf('%s :', $key));
                foreach ($val as $k => $v) {
                    $io->text(sprintf('  %s = %s', $k, $this->formatScalar($v)));
                }
            } else {
                $io->text(sprintf('%s = %s', $key, $this->formatScalar($val)));
            }
        }

        // Coût exact lu depuis synapse_llm_call
        $this->renderCostFromDb($io, $startedAt);

        return Command::SUCCESS;
    }

    private function snippet(ScoredNeuron $scored): string
    {
        $n = $scored->neuron;
        $text = match (true) {
            $n instanceof SemanticNeuron => sprintf('%s %s %s', $n->getSubject(), $n->getPredicate(), $n->getValue()),
            $n instanceof EpisodicNeuron => $n->getEventSummary(),
            $n instanceof EncyclopedicNeuron => $n->getChunkContent(),
            $n instanceof ProceduralNeuron => $n->getName(),
            default => '(neurone non descriptible)',
        };
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        if (mb_strlen($text) > 80) {
            $text = mb_substr($text, 0, 77).'…';
        }

        return $text;
    }

    private function formatScalar(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? 'true' : 'false',
            is_float($v) => sprintf('%.4f', $v),
            is_scalar($v) => (string) $v,
            null === $v => 'null',
            default => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '?',
        };
    }

    private function renderCostFromDb(SymfonyStyle $io, \DateTimeImmutable $since): void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(SynapseLlmCall::class, 'c')
            ->where('c.createdAt >= :since')
            ->andWhere('c.module = :module')
            ->setParameter('since', $since)
            ->setParameter('module', 'brain')
            ->orderBy('c.createdAt', 'ASC');

        /** @var list<SynapseLlmCall> $calls */
        $calls = $qb->getQuery()->getResult();

        if ([] === $calls) {
            $io->section('Coût LLM');
            $io->text('Aucun appel LLM tracé (module=brain) pour cette query.');
            $io->text('Note : si embedding ou retrieval n\'est pas tagué module=brain, élargir le filtre.');

            return;
        }

        $io->section('Coût LLM (synapse_llm_call)');

        $rows = [];
        $totalRef = 0.0;
        $currency = 'USD';
        foreach ($calls as $call) {
            $cost = (float) ($call->getCostReference() ?? 0.0);
            $totalRef += $cost;
            $rows[] = [
                $call->getAction(),
                $call->getModel(),
                (string) $call->getPromptTokens(),
                (string) $call->getCompletionTokens(),
                sprintf('%.6f', $cost),
            ];
        }

        $io->table(['Action', 'Modèle', 'In tokens', 'Out tokens', 'Coût €/USD'], $rows);
        $io->text(sprintf('Total : %.6f %s sur %d appel(s)', $totalRef, $currency, count($calls)));
    }
}
