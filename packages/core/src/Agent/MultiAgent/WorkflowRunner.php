<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\NodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Message\ExecuteWorkflowMessage;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Point d'entrée de service pour l'exécution synchrone d'un {@see SynapseWorkflow} (Phase 8).
 *
 * `WorkflowRunner` complète {@see MultiAgent} en prenant en charge tout ce qui relève
 * de la persistance et du cycle de vie du {@see SynapseWorkflowRun} :
 *
 * 1. Crée une nouvelle instance de `SynapseWorkflowRun`, dénormalise `workflowKey` /
 *    `workflowVersion` / `stepsCount` via `setWorkflow()`, et la persiste en base
 *    (statut initial `PENDING`).
 * 2. Instancie un {@see MultiAgent} avec le workflow et le run, puis délègue l'exécution
 *    via `call()`. `MultiAgent` mute le run en RAM (status, currentStepIndex, totalTokens,
 *    errorMessage, completedAt) mais ne flush jamais.
 * 3. En succès comme en échec, flush les mutations — le run trace toujours l'issue de
 *    l'exécution (COMPLETED ou FAILED).
 * 4. Retourne l'{@see Output} produit par le `MultiAgent` à l'appelant.
 *
 * ## Responsabilités laissées au caller
 *
 * - Garantir que la définition du workflow est valide et exécutable (Phase 7 a livré
 *   la validation minimale côté admin ; les erreurs d'agent_name inconnu, elles,
 *   remontent depuis `MultiAgent::preValidateAgents()`).
 * - Passer un `AgentContext` via `$options['context']` s'il est déjà dans un arbre
 *   d'agents. Sinon, `MultiAgent` créera un contexte racine via `AgentResolver`.
 * - Capturer l'{@see WorkflowExecutionException} si le caller a besoin d'un traitement
 *   spécifique (UI admin, retry, notification). Le runner se contente de rethrow
 *   après avoir flushé.
 *
 * ## Async / Messenger
 *
 * `WorkflowRunner` est **synchrone**. L'exécution asynchrone via Messenger (Phase 9)
 * réutilisera ce service en l'appelant depuis un message handler.
 */
class WorkflowRunner
{
    private readonly LoggerInterface $logger;

    /**
     * @var iterable<NodeExecutorInterface>
     */
    private readonly iterable $nodeExecutors;

    /**
     * @param iterable<NodeExecutorInterface> $nodeExecutors Collection des exécuteurs de nœuds
     *                                                      (Chantier F), découverts via le tag DI
     *                                                      `synapse.node_executor`. Passée telle
     *                                                      quelle à `MultiAgent` à chaque run.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentResolver $resolver,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
        private readonly ?SynapseDebugLogRepository $debugLogRepository = null,
        private readonly ?MessageBusInterface $messageBus = null,
        #[AutowireIterator('synapse.node_executor')]
        iterable $nodeExecutors = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
        // Matérialiser une fois en array : MultiAgent est instancié à chaque run,
        // autant éviter de re-itérer un générateur rewindable par run.
        $this->nodeExecutors = is_array($nodeExecutors)
            ? $nodeExecutors
            : iterator_to_array($nodeExecutors, false);
    }

    /**
     * Lance un workflow de manière synchrone et retourne son {@see Output} final.
     *
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     *
     * @throws WorkflowExecutionException Si la définition est invalide ou si un step échoue.
     *                                    Dans tous les cas, le `SynapseWorkflowRun` est flushé
     *                                    avec le statut final (FAILED) et l'errorMessage.
     */
    public function run(SynapseWorkflow $workflow, Input $input, array $options = []): Output
    {
        $run = $this->createRun($workflow, $options);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $this->executeRun($workflow, $run, $input, $options);
    }

    /**
     * Version asynchrone de {@see run()} — Chantier G.
     *
     * Crée un run en statut PENDING côté caller (sans l'exécuter), dispatch
     * un {@see ExecuteWorkflowMessage} via Messenger, et retourne immédiatement
     * le run pour que l'appelant ait un UUID à tracker (via
     * `inspect_workflow_run` MCP ou admin UI).
     *
     * Le handler ({@see \ArnaudMoncondhuy\SynapseCore\MessageHandler\ExecuteWorkflowMessageHandler})
     * reprendra l'exécution via {@see resumeRun()} quand un worker Messenger
     * dépile le message.
     *
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     *
     * @throws \RuntimeException Si le MessageBus n'est pas injecté (bundle mal configuré).
     */
    public function runAsync(SynapseWorkflow $workflow, Input $input, array $options = []): SynapseWorkflowRun
    {
        if (null === $this->messageBus) {
            throw new \RuntimeException('WorkflowRunner::runAsync() requires a MessageBusInterface. Either inject one or use run() for synchronous execution.');
        }

        $run = $this->createRun($workflow, $options);
        // Statut PENDING explicite (défaut déjà PENDING, on le set pour être clair).
        $run->setStatus(WorkflowRunStatus::PENDING);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $structured = $input->getStructured();
        $message = new ExecuteWorkflowMessage(
            workflowKey: $workflow->getWorkflowKey(),
            structuredInput: $structured,
            userId: $run->getUserId(),
            message: $input->getMessage(),
            runId: $run->getWorkflowRunId(),
        );
        $this->messageBus->dispatch($message);

        $this->logger->info('Workflow run {workflowRunId} dispatched async for workflow "{workflowKey}"', [
            'workflowRunId' => $run->getWorkflowRunId(),
            'workflowKey' => $run->getWorkflowKey(),
        ]);

        return $run;
    }

    /**
     * Reprend l'exécution d'un `SynapseWorkflowRun` déjà persisté (typiquement
     * créé par {@see runAsync()} et dépilé par le handler Messenger).
     *
     * Utilisé aussi pour les retries : si un run a échoué transitoirement,
     * on peut le remettre en RUNNING et relancer.
     *
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     */
    public function resumeRun(SynapseWorkflow $workflow, SynapseWorkflowRun $run, Input $input, array $options = []): Output
    {
        return $this->executeRun($workflow, $run, $input, $options);
    }

    /**
     * Logique d'exécution commune à `run()` et `resumeRun()` : instancie un
     * `MultiAgent`, le laisse muter le run, flush, agrège les coûts.
     *
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     */
    private function executeRun(SynapseWorkflow $workflow, SynapseWorkflowRun $run, Input $input, array $options): Output
    {
        $this->logger->info('Workflow run {workflowRunId} started for workflow "{workflowKey}" (version {version})', [
            'workflowRunId' => $run->getWorkflowRunId(),
            'workflowKey' => $run->getWorkflowKey(),
            'version' => $run->getWorkflowVersion(),
        ]);

        $multiAgent = new MultiAgent(
            $workflow,
            $run,
            $this->resolver,
            $this->nodeExecutors,
            $this->eventDispatcher,
            $this->logger,
        );

        try {
            $output = $multiAgent->call($input, $options);
        } catch (\Throwable $e) {
            // MultiAgent a déjà positionné status=FAILED + errorMessage + completedAt.
            // On flush quand même pour persister l'échec, puis on rethrow.
            if (WorkflowRunStatus::FAILED !== $run->getStatus()) {
                // Ceinture + bretelles : MultiAgent est censé avoir fait markFailed(),
                // mais si une exception inattendue (avant ou après les catches internes)
                // remonte, on garantit quand même l'état cohérent.
                $run->setStatus(WorkflowRunStatus::FAILED);
                $run->setErrorMessage($e->getMessage());
                $run->setCompletedAt(new \DateTimeImmutable());
            }
            $this->entityManager->flush();

            throw $e;
        }

        // Chantier B : agrège les coûts de tous les debug logs produits pendant
        // le run et les dénormalise sur SynapseWorkflowRun::$totalCost. Permet
        // aux UIs admin et aux hard limits (BudgetLimit) de raisonner en O(1)
        // sans requery des debug logs à chaque lecture.
        if (null !== $this->debugLogRepository) {
            $totalCost = $this->debugLogRepository->sumCostByWorkflowRunId($run->getWorkflowRunId());
            if (null !== $totalCost) {
                $run->setTotalCost($totalCost);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Workflow run {workflowRunId} completed in {duration}s with status {status} — cost {cost} EUR', [
            'workflowRunId' => $run->getWorkflowRunId(),
            'duration' => $run->getDurationSeconds(),
            'status' => $run->getStatus()->value,
            'cost' => $run->getTotalCost() ?? 0,
        ]);

        return $output;
    }

    /**
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     */
    private function createRun(SynapseWorkflow $workflow, array $options): SynapseWorkflowRun
    {
        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow); // dénormalise workflowKey, workflowVersion, stepsCount

        // userId : priorité à l'option explicite, sinon au contexte fourni, sinon null.
        if (isset($options['user_id']) && is_string($options['user_id'])) {
            $run->setUserId($options['user_id']);
        } elseif (isset($options['context'])) {
            $context = $options['context'];
            if ($context instanceof \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext) {
                $run->setUserId($context->getUserId());
            }
        }

        return $run;
    }
}
