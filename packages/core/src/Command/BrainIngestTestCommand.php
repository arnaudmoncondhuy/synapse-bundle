<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\MemoryExtractor;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\MemoryFragmentSerializer;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
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
 * Test de sortie du jalon 2 — ingestion mono-aire.
 *
 * Charge une MemorySource par UUID, déclenche l'extraction sur l'aire
 * demandée via MemoryExtractor, et affiche le résultat.
 *
 * Mode dry-run par défaut (les neurones extraits sont affichés mais pas
 * persistés). Avec `--persist`, on flushe en BDD.
 *
 * Nécessite une **configuration LLM réelle côté app hôte** (preset actif)
 * pour les aires Semantic et Episodic. L'aire Encyclopedic nécessite un
 * provider embedding configuré.
 *
 * Ref: docs/brain/06-phases/jalon-2-ingestion-mono-aire.md §2
 */
#[AsCommand(
    name: 'brain:ingest:test',
    description: 'Ingère une MemorySource et extrait des neurones via MemoryExtractor (test de sortie jalon 2).',
)]
final class BrainIngestTestCommand extends Command
{
    public function __construct(
        private readonly MemoryExtractor $memoryExtractor,
        private readonly MemorySourceRepository $sourceRepo,
        private readonly EntityManagerInterface $em,
        private readonly MemoryFragmentSerializer $serializer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'source-id',
                null,
                InputOption::VALUE_REQUIRED,
                'UUID de la MemorySource à ingérer',
            )
            ->addOption(
                'area',
                null,
                InputOption::VALUE_REQUIRED,
                'Aire visée (semantic, episodic, encyclopedic, procedural). Ignoré si --multi-area est présent.',
                'semantic',
            )
            ->addOption(
                'multi-area',
                null,
                InputOption::VALUE_NONE,
                'Mode multi-aires (jalon 3) — 1 passe LLM produit des neurones dans toutes les aires actives.',
            )
            ->addOption(
                'persist',
                null,
                InputOption::VALUE_NONE,
                'Persister les neurones extraits en BDD (défaut: dry-run, pas de persistance)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceIdRaw = $input->getOption('source-id');
        if (!is_string($sourceIdRaw) || '' === $sourceIdRaw) {
            $io->error('--source-id est requis (UUID d\'une MemorySource existante).');

            return Command::INVALID;
        }

        try {
            $sourceUuid = Uuid::fromString($sourceIdRaw);
        } catch (\InvalidArgumentException $e) {
            $io->error('--source-id n\'est pas un UUID valide : '.$e->getMessage());

            return Command::INVALID;
        }

        $multiArea = (bool) $input->getOption('multi-area');

        $area = null;
        if (!$multiArea) {
            $areaRaw = $input->getOption('area');
            $areaValue = is_string($areaRaw) ? $areaRaw : 'semantic';
            $area = BrainArea::tryFrom($areaValue);
            if (null === $area) {
                $io->error(sprintf(
                    '--area "%s" inconnue. Valeurs valides : %s.',
                    $areaValue,
                    implode(', ', array_map(static fn (BrainArea $a) => $a->value, BrainArea::cases())),
                ));

                return Command::INVALID;
            }
        }

        $source = $this->sourceRepo->findOneByUuid($sourceUuid);
        if (null === $source) {
            $io->error(sprintf('MemorySource %s introuvable.', $sourceUuid->toRfc4122()));

            return Command::FAILURE;
        }

        $modeLabel = $multiArea ? 'multi-aires (1 passe LLM)' : sprintf('aire %s', $area->value);
        $io->title(sprintf('Brain — Ingestion test : %s', $modeLabel));
        $io->writeln(sprintf('Source UUID : %s', $source->getId()->toRfc4122()));
        $io->writeln(sprintf('Provider    : %s', $source->getProvider()));
        $io->writeln(sprintf('Reçue       : %s', $source->getReceivedAt()->format('c')));
        $io->newLine();

        // En multi-aires, on appelle extractAll qui retourne plusieurs
        // ExtractionResult. En mono-aire, on appelle extract.
        try {
            $results = $multiArea
                ? $this->memoryExtractor->extractAll($source)
                : [$this->memoryExtractor->extract($source, $area)];
        } catch (ExtractionFailedException $e) {
            $io->error('Extraction échouée : '.$e->getMessage());

            return Command::FAILURE;
        }

        // Rendu uniforme : un bloc par ExtractionResult
        $totalNeurons = 0;
        foreach ($results as $r) {
            $totalNeurons += $r->count();
        }

        $io->section(sprintf(
            '%d aire(s) extraite(s) — %d neurone(s) au total',
            count($results),
            $totalNeurons,
        ));

        if ([] === $results) {
            $io->warning('Aucun résultat retourné par l\'extracteur.');

            return Command::SUCCESS;
        }

        // On affiche le 1er debug global (model, usage) puis chaque aire
        $io->writeln($this->renderDebug($results[0]->debug));
        $io->newLine();

        $allNeurons = [];
        foreach ($results as $r) {
            $io->section(sprintf('Aire %s — %d neurone(s)', $r->area->value, $r->count()));
            foreach ($r->neurons as $neuron) {
                $allNeurons[] = $neuron;
            }
        }

        if (0 === $totalNeurons) {
            $io->success('Aucun neurone extrait (sélectivité naturelle).');

            return Command::SUCCESS;
        }

        foreach ($allNeurons as $i => $neuron) {
            $io->section(sprintf('Neurone #%d', $i + 1));
            $payload = $this->serializer->serialize($neuron);
            $io->writeln(
                json_encode(
                    $payload,
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                ),
            );
        }

        if ($input->getOption('persist')) {
            foreach ($allNeurons as $neuron) {
                $this->em->persist($neuron);
            }
            $this->em->flush();
            $io->success(sprintf('%d neurone(s) persisté(s) en BDD.', $totalNeurons));
        } else {
            $io->warning('Dry-run : neurones non persistés. Relancer avec --persist pour les sauvegarder.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $debug
     */
    private function renderDebug(array $debug): string
    {
        return json_encode(
            $debug,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }
}
