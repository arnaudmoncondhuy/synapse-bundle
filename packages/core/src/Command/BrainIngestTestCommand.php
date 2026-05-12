<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\MemoryExtractor;
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
                'Aire visée (semantic, episodic, encyclopedic)',
                'semantic',
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

        $source = $this->sourceRepo->findOneByUuid($sourceUuid);
        if (null === $source) {
            $io->error(sprintf('MemorySource %s introuvable.', $sourceUuid->toRfc4122()));

            return Command::FAILURE;
        }

        $io->title(sprintf('Brain — Ingestion test : aire %s', $area->value));
        $io->writeln(sprintf('Source UUID : %s', $source->getId()->toRfc4122()));
        $io->writeln(sprintf('Provider    : %s', $source->getProvider()));
        $io->writeln(sprintf('Reçue       : %s', $source->getReceivedAt()->format('c')));
        $io->newLine();

        try {
            $result = $this->memoryExtractor->extract($source, $area);
        } catch (ExtractionFailedException $e) {
            $io->error('Extraction échouée : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->section(sprintf('Résultat — %d neurone(s) extrait(s)', $result->count()));
        $io->writeln($this->renderDebug($result->debug));

        if ($result->isEmpty()) {
            $io->success('Aucun neurone extrait (sélectivité naturelle).');

            return Command::SUCCESS;
        }

        foreach ($result->neurons as $i => $neuron) {
            $io->section(sprintf('Neurone #%d', $i + 1));
            $payload = $this->renderNeuronPayload($neuron);
            $io->writeln(
                json_encode(
                    $payload,
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                ),
            );
        }

        if ($input->getOption('persist')) {
            foreach ($result->neurons as $neuron) {
                $this->em->persist($neuron);
            }
            $this->em->flush();
            $io->success(sprintf('%d neurone(s) persisté(s) en BDD.', $result->count()));
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

    /**
     * @return array<string, mixed>
     */
    private function renderNeuronPayload(object $neuron): array
    {
        // Sérialisation minimale agnostique de l'aire — on appelle les getters
        // public communs et on capture le reste via reflection des propriétés
        // visibles.
        $payload = [
            'class' => $neuron::class,
        ];

        if (method_exists($neuron, 'getId')) {
            $id = $neuron->getId();
            if (is_object($id) && method_exists($id, 'toRfc4122')) {
                $payload['id'] = $id->toRfc4122();
            }
        }
        if (method_exists($neuron, 'getArea')) {
            $area = $neuron->getArea();
            if ($area instanceof BrainArea) {
                $payload['area'] = $area->value;
            }
        }
        if (method_exists($neuron, 'getSourceUuid')) {
            $src = $neuron->getSourceUuid();
            if (is_object($src) && method_exists($src, 'toRfc4122')) {
                $payload['sourceUuid'] = $src->toRfc4122();
            }
        }

        // Champs spécifiques aux 3 aires du jalon 2
        foreach (['getSubject', 'getPredicate', 'getValue', 'getConfidence', 'getEventSummary', 'getActors', 'getLocation', 'getOccurredAt', 'getDocumentRef', 'getChunkIndex', 'getTotalChunks', 'getChunkContent'] as $getter) {
            if (method_exists($neuron, $getter)) {
                $value = $neuron->$getter();
                if ($value instanceof \DateTimeImmutable) {
                    $value = $value->format('c');
                }
                $key = lcfirst(substr($getter, 3));
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
