<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Garbage collector pour les entités éphémères Synapse.
 *
 * Supprime les workflows, agents et presets marqués `isEphemeral: true` dont
 * la fenêtre de rétention ({@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow::$retentionUntil}
 * etc.) est dépassée. Les runs de workflow éphémères expirés sont également
 * supprimés. Les `SynapseDebugLog` sont **toujours** préservés — ils constituent
 * l'historique d'audit et ne doivent pas être nettoyés par ce GC.
 *
 * Appelable en cron (typiquement une fois par jour) :
 * ```
 * 0 3 * * * cd /var/www && bin/console synapse:ephemeral:gc
 * ```
 *
 * Pour un dry-run (lister sans supprimer) : `--dry-run`.
 */
#[AsCommand(
    name: 'synapse:ephemeral:gc',
    description: 'Supprime les entités éphémères expirées (workflows, agents, presets) dont la fenêtre de rétention est dépassée.',
)]
class EphemeralGcCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly SynapseWorkflowRunRepository $runRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lister les entités éligibles sans les supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synapse Ephemeral GC');

        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();

        $expiredWorkflows = $this->workflowRepository->findExpiredEphemeral($now);
        $expiredAgents = $this->agentRepository->findExpiredEphemeral($now);
        $expiredPresets = $this->presetRepository->findExpiredEphemeral($now);

        if ([] === $expiredWorkflows && [] === $expiredAgents && [] === $expiredPresets) {
            $io->success('Rien à nettoyer — aucune entité éphémère expirée.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Workflows expirés (%d)', count($expiredWorkflows)));
        foreach ($expiredWorkflows as $w) {
            $io->writeln(sprintf('  - %s (%s) — retention_until=%s', $w->getName(), $w->getWorkflowKey(), $w->getRetentionUntil()?->format('c') ?? 'NULL (legacy)'));
        }

        $io->section(sprintf('Agents expirés (%d)', count($expiredAgents)));
        foreach ($expiredAgents as $a) {
            $io->writeln(sprintf('  - %s (%s) — retention_until=%s', $a->getName(), $a->getKey(), $a->getRetentionUntil()?->format('c') ?? 'NULL (legacy)'));
        }

        $io->section(sprintf('Presets expirés (%d)', count($expiredPresets)));
        foreach ($expiredPresets as $p) {
            $io->writeln(sprintf('  - %s (%s) — retention_until=%s', $p->getName(), $p->getKey(), $p->getRetentionUntil()?->format('c') ?? 'NULL (legacy)'));
        }

        if ($dryRun) {
            $io->note('Dry-run activé — aucune suppression effectuée.');

            return Command::SUCCESS;
        }

        // 1. Runs des workflows expirés
        $workflowKeys = array_map(static fn ($w) => $w->getWorkflowKey(), $expiredWorkflows);
        $runsDeleted = [] !== $workflowKeys ? $this->runRepository->deleteByWorkflowKeys($workflowKeys) : 0;

        // 2. Workflows
        foreach ($expiredWorkflows as $workflow) {
            $this->entityManager->remove($workflow);
        }

        // 3. Agents (avant presets — agent → preset en ManyToOne)
        foreach ($expiredAgents as $agent) {
            $this->entityManager->remove($agent);
        }

        // 4. Presets
        foreach ($expiredPresets as $preset) {
            $this->entityManager->remove($preset);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Nettoyage terminé : %d workflow(s), %d run(s), %d agent(s), %d preset(s) supprimés.',
            count($expiredWorkflows),
            $runsDeleted,
            count($expiredAgents),
            count($expiredPresets),
        ));

        return Command::SUCCESS;
    }
}
