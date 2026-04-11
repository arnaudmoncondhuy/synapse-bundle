<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor\NodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseWorkflowStepCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseWorkflowStepStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Moteur d'exécution séquentiel d'un {@see SynapseWorkflow} (Phase 8).
 *
 * `MultiAgent` implémente {@see AgentInterface} pour respecter l'alignement terminologique
 * `symfony/ai` : un workflow **est** un agent — il prend un {@see Input}, retourne un
 * {@see Output}, et peut à son tour être appelé depuis un autre contexte d'agent.
 *
 * ## Responsabilités
 *
 * 1. Valider au runtime que tous les agents référencés par les steps sont résolvables
 *    via {@see AgentResolver::has()}.
 * 2. Pour chaque step (exécution strictement séquentielle) :
 *    - construire l'input à partir de `input_mapping` et de l'état accumulé ;
 *    - créer un contexte enfant ({@see AgentContext::createChild()}) enrichi avec le
 *      `workflowRunId` du run, pour que tous les {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog}
 *      produits remontent dans l'arbre du workflow ;
 *    - appeler l'agent résolu et stocker son output ;
 *    - mettre à jour `currentStepIndex` + `totalTokens` sur le run.
 * 3. Appliquer les expressions `outputs` finales pour construire le {@see Output::$data}
 *    final retourné à l'appelant.
 *
 * ## Responsabilités déléguées (hors scope)
 *
 * - **Persistance du run** : déléguée à {@see WorkflowRunner}. `MultiAgent` mute le
 *   `SynapseWorkflowRun` en mémoire mais ne flush jamais.
 * - **Async / Messenger** : Phase 9.
 * - **Parallélisme, DAG, handoff conditionnel** : Phase 10+.
 * - **Pricing** : `totalCost` reste à `null` — le calcul de coût unifié vit dans
 *   {@see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService} et n'est pas répliqué ici
 *   (single source of truth).
 */
final class MultiAgent implements AgentInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @var list<NodeExecutorInterface>
     */
    private readonly array $nodeExecutors;

    /**
     * @param iterable<NodeExecutorInterface> $nodeExecutors Collection des exécuteurs de nœuds
     *                                                      (Chantier F). Typiquement injectée par
     *                                                      `WorkflowRunner` via AutowireIterator,
     *                                                      ou passée explicitement dans les tests.
     *                                                      Doit contenir au moins un executor qui
     *                                                      supporte le type `agent` (sinon les
     *                                                      workflows historiques cassent).
     */
    public function __construct(
        private readonly SynapseWorkflow $workflow,
        private readonly SynapseWorkflowRun $run,
        private readonly AgentResolver $resolver,
        iterable $nodeExecutors,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        $this->nodeExecutors = is_array($nodeExecutors)
            ? array_values($nodeExecutors)
            : iterator_to_array($nodeExecutors, false);
    }

    public function getName(): string
    {
        return 'workflow:'.$this->workflow->getWorkflowKey();
    }

    public function getLabel(): string
    {
        return $this->workflow->getName();
    }

    public function getDescription(): string
    {
        return $this->workflow->getDescription() ?? $this->workflow->getName();
    }

    /**
     * Exécute le workflow de bout en bout.
     *
     * Le run reçu en constructeur est muté au fil des steps (status, currentStepIndex,
     * totalTokens, errorMessage, completedAt). Aucun `flush` n'est appelé — c'est la
     * responsabilité du {@see WorkflowRunner}.
     *
     * @param Input $input Inputs initiaux du workflow. La clé `structured` est utilisée
     *                     en priorité pour alimenter `$state['inputs']` ; si elle est vide,
     *                     le message texte est posé sous `$state['inputs']['message']`.
     * @param array<string, mixed> $options peut contenir `'context' => AgentContext` (sinon
     *                                      un contexte racine est créé via le resolver)
     *
     * @throws WorkflowExecutionException si la définition est invalide ou si un step échoue
     */
    public function call(Input $input, array $options = []): Output
    {
        $definition = $this->workflow->getDefinition();
        $steps = $this->extractSteps($definition);

        // Pré-validation : chaque step doit avoir un exécuteur connu ; les steps
        // `agent` doivent en plus pointer sur un agent_name résolvable.
        $this->preValidateSteps($steps);

        // Contexte parent : soit fourni par le caller, soit nouveau contexte racine.
        $parentContext = $options['context'] ?? null;
        if (!$parentContext instanceof AgentContext) {
            $parentContext = $this->resolver->createRootContext(
                userId: $this->run->getUserId(),
                origin: 'workflow',
            );
        }
        // Enrichir avec le workflowRunId du run courant (tous les enfants hériteront via createChild).
        $parentContext = $parentContext->withWorkflowRunId($this->run->getWorkflowRunId());

        // State accumulé : inputs initiaux + outputs de chaque step au fur et à mesure.
        $state = [
            'inputs' => $this->buildInitialInputs($input),
            'steps' => [],
        ];

        $this->run->setStatus(WorkflowRunStatus::RUNNING);
        $this->run->setInput($state['inputs']);

        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;

        foreach ($steps as $index => $step) {
            $stepName = (string) $step['name'];
            $stepType = (string) ($step['type'] ?? 'agent');
            // Pour les events et la dénorm du run, on n'a pas d'agent_name sur
            // les steps non-agent (ex: conditional). On fallback sur le type
            // pour que la sidebar affiche "conditional" au lieu d'un champ vide.
            $agentName = isset($step['agent_name']) && is_string($step['agent_name'])
                ? $step['agent_name']
                : $stepType;
            $this->run->setCurrentStepIndex($index);

            // Dispatch step started event — la sidebar affiche immédiatement que ce step réfléchit.
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new SynapseWorkflowStepStartedEvent(
                    workflowRunId: $this->run->getWorkflowRunId(),
                    workflowKey: $this->workflow->getWorkflowKey(),
                    stepIndex: $index,
                    stepName: $stepName,
                    agentName: $agentName,
                    totalSteps: \count($steps),
                ));
            }

            try {
                $stepInput = $this->resolveStepInput($step, $state, $stepName);

                // Chantier H4 : capture l'input résolu pour permettre le
                // replay ultérieur du step via l'action admin replayStep().
                // On le persiste sur le run avant même l'appel à l'agent :
                // si l'agent plante, on a quand même la trace de ce qui a
                // été tenté et on peut rejouer pour debug.
                $this->run->addStepInput($stepName, $stepInput);

                $childContext = $parentContext->createChild(
                    parentRunId: $parentContext->getRequestId(),
                    childOrigin: 'workflow',
                );

                // Chantier F : dispatch vers l'exécuteur de nœud adapté au type.
                // Historique (pré-F) = uniquement `agent`. Aujourd'hui le dispatch
                // permet d'ajouter `conditional` (et plus tard `parallel`, `loop`,
                // `sub_workflow`) sans toucher à cette boucle orchestratrice.
                $executor = $this->pickExecutor($stepType, $stepName);
                $stepOutput = $executor->execute($step, $stepInput, $state, $childContext);
            } catch (WorkflowExecutionException $e) {
                $this->markFailed($e->getMessage());
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->error('Workflow step "{step}" raised: {error}', [
                    'step' => $stepName,
                    'error' => $e->getMessage(),
                    'workflow_run_id' => $this->run->getWorkflowRunId(),
                ]);
                $this->markFailed(sprintf('Step "%s": %s', $stepName, $e->getMessage()));
                throw WorkflowExecutionException::stepFailed($stepName, $e->getMessage(), $e);
            }

            // Stocker l'output du step sous `$state['steps'][NAME]['output']`.
            $state['steps'][$stepName] = [
                'output' => [
                    'text' => $stepOutput->getAnswer(),
                    'data' => $stepOutput->getData(),
                    'generated_attachments' => $stepOutput->getGeneratedAttachments(),
                ],
            ];

            // Agrégation des usages — cohérent avec TokenUsage keys.
            $usage = $stepOutput->getUsage();
            if (isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])) {
                $totalPromptTokens += $usage['prompt_tokens'];
            }
            if (isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])) {
                $totalCompletionTokens += $usage['completion_tokens'];
            }

            // Dispatch step completed event pour le streaming sidebar "réflexion interne".
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new SynapseWorkflowStepCompletedEvent(
                    workflowRunId: $this->run->getWorkflowRunId(),
                    workflowKey: $this->workflow->getWorkflowKey(),
                    stepIndex: $index,
                    stepName: $stepName,
                    agentName: $agentName,
                    answer: $stepOutput->getAnswer(),
                    usage: $usage,
                    totalSteps: \count($steps),
                ));
            }
        }

        // Tous les steps sont passés — avancer l'index au-delà du dernier et finaliser.
        $this->run->setCurrentStepIndex(count($steps));
        $this->run->setTotalTokens($totalPromptTokens + $totalCompletionTokens);

        // Construire l'output final à partir de la clause `outputs` de la définition.
        $finalOutputs = $this->resolveFinalOutputs($definition, $state);

        $this->run->setStatus(WorkflowRunStatus::COMPLETED);
        $this->run->setOutput($finalOutputs);
        $this->run->setCompletedAt(new \DateTimeImmutable());

        // Construire la réponse textuelle : concaténation des outputs non-vides.
        // Quand un seul output est non-vide, il devient la réponse directe (pas de séparateur).
        $textParts = [];
        foreach ($finalOutputs as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $textParts[] = $value;
            }
        }
        $answer = implode("\n\n---\n\n", $textParts);

        // Agréger les generated_attachments de tous les steps (images, fichiers),
        // enrichis avec la provenance (step_name, step_index) et la taille décodée
        // pour que `inspect_workflow_run` puisse exposer un summary sans base64.
        $allAttachments = [];
        $stepNameToIndex = [];
        foreach ($steps as $idx => $stepDef) {
            if (is_array($stepDef) && isset($stepDef['name']) && is_string($stepDef['name'])) {
                $stepNameToIndex[$stepDef['name']] = $idx;
            }
        }
        foreach ($state['steps'] as $currentStepName => $stepData) {
            /** @var array{output: array{text: string|null, data: array<string, mixed>, generated_attachments: array<int, array<string, mixed>>}} $stepData */
            foreach ($stepData['output']['generated_attachments'] as $att) {
                if (!is_array($att) || !isset($att['mime_type'], $att['data'])) {
                    continue;
                }
                $rawData = is_string($att['data']) ? $att['data'] : '';
                // Taille décodée approximative : base64 expand ratio = 4/3.
                $sizeBytes = (int) \floor(strlen($rawData) * 3 / 4);
                $allAttachments[] = [
                    'step_name' => (string) $currentStepName,
                    'step_index' => $stepNameToIndex[$currentStepName] ?? -1,
                    'mime_type' => (string) $att['mime_type'],
                    'data' => $rawData,
                    'size_bytes' => $sizeBytes,
                ];
            }
        }

        // Persister sur le run pour que `inspect_workflow_run` et l'admin puissent
        // les retrouver après la fin de l'exécution.
        if ([] !== $allAttachments) {
            $this->run->setGeneratedAttachments($allAttachments);
        }

        return new Output(
            answer: '' !== $answer ? $answer : null,
            data: $finalOutputs,
            usage: [
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
                'total_tokens' => $totalPromptTokens + $totalCompletionTokens,
            ],
            generatedAttachments: $allAttachments,
            metadata: [
                'workflow_key' => $this->workflow->getWorkflowKey(),
                'workflow_version' => $this->run->getWorkflowVersion(),
                'workflow_run_id' => $this->run->getWorkflowRunId(),
                'steps_executed' => count($steps),
            ],
        );
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractSteps(array $definition): array
    {
        $steps = $definition['steps'] ?? null;
        if (!is_array($steps) || [] === $steps) {
            throw WorkflowExecutionException::invalidDefinition('steps array is missing or empty');
        }

        $normalized = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw WorkflowExecutionException::invalidDefinition(sprintf('step at index %s is not an object', (string) $index));
            }
            if (!isset($step['name']) || !is_string($step['name']) || '' === $step['name']) {
                throw WorkflowExecutionException::invalidDefinition(sprintf('step at index %s has no name', (string) $index));
            }
            // Chantier F : `agent_name` n'est plus requis au niveau de l'extraction — la
            // pré-validation type-aware dans `preValidateSteps()` s'en occupe selon que
            // le step est de type `agent` ou d'un autre type (ex: `conditional`).
            $normalized[] = $step;
        }

        return $normalized;
    }

    /**
     * Pré-validation des steps (Chantier F) : type-aware.
     *
     * - Un step `agent` (ou sans type, BC) doit pointer sur un `agent_name`
     *   résolvable via {@see AgentResolver::has()}. C'est ce qui garantit qu'on
     *   n'entame pas l'exécution d'un workflow dont on sait déjà qu'il planterait
     *   à mi-parcours sur un agent inconnu.
     * - Un step non-agent doit simplement pouvoir être pris en charge par au
     *   moins un {@see NodeExecutorInterface} enregistré. Les validations
     *   spécifiques au type (ex: `condition` présente pour `conditional`) sont
     *   déléguées à l'exécuteur lui-même — il jettera `invalidDefinition` au
     *   moment d'exécuter si son step est malformé.
     *
     * @param array<int, array<string, mixed>> $steps
     */
    private function preValidateSteps(array $steps): void
    {
        foreach ($steps as $step) {
            $stepName = (string) $step['name'];
            $type = (string) ($step['type'] ?? 'agent');

            if ('agent' === $type) {
                $agentName = $step['agent_name'] ?? null;
                if (!is_string($agentName) || '' === $agentName) {
                    throw WorkflowExecutionException::invalidDefinition(
                        sprintf('agent step "%s" has no agent_name', $stepName)
                    );
                }
                if (!$this->resolver->has($agentName)) {
                    throw WorkflowExecutionException::agentNotResolvable($stepName, $agentName);
                }

                continue;
            }

            // Step non-agent : il suffit qu'un exécuteur le prenne en charge.
            // Pas de check plus fin ici — à l'exécuteur de valider son format.
            foreach ($this->nodeExecutors as $executor) {
                if ($executor->supports($type)) {
                    continue 2;
                }
            }

            throw WorkflowExecutionException::invalidDefinition(
                sprintf('step "%s" has unknown type "%s" (no NodeExecutor registered)', $stepName, $type)
            );
        }
    }

    /**
     * Trouve le premier exécuteur qui déclare supporter le type du step.
     *
     * @throws WorkflowExecutionException Si aucun exécuteur ne matche (typiquement
     *                                    un type ajouté à la def sans avoir tagué
     *                                    un service {@see NodeExecutorInterface} qui
     *                                    le gère).
     */
    private function pickExecutor(string $type, string $stepName): NodeExecutorInterface
    {
        foreach ($this->nodeExecutors as $executor) {
            if ($executor->supports($type)) {
                return $executor;
            }
        }

        throw WorkflowExecutionException::invalidDefinition(
            sprintf('step "%s" has unknown type "%s" (no NodeExecutor registered)', $stepName, $type)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInitialInputs(Input $input): array
    {
        $structured = $input->getStructured();
        if ([] !== $structured) {
            return $structured;
        }

        return ['message' => $input->getMessage()];
    }

    /**
     * Construit l'input à passer à un step en résolvant son `input_mapping`.
     *
     * Chantier F phase 2 : la logique est extraite dans {@see StepInputResolver}
     * pour être partagée avec les exécuteurs composites (`parallel`, `loop`)
     * qui doivent résoudre des sous-steps. Ce wrapper garde la signature
     * existante et passe les références au run/workflow pour la log de warning.
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function resolveStepInput(array $step, array $state, string $stepName): array
    {
        return StepInputResolver::resolve(
            step: $step,
            state: $state,
            logger: $this->logger,
            stepName: $stepName,
            workflowRunId: $this->run->getWorkflowRunId(),
            workflowKey: $this->workflow->getWorkflowKey(),
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function resolveFinalOutputs(array $definition, array $state): array
    {
        $outputs = $definition['outputs'] ?? null;
        if (!is_array($outputs)) {
            return [];
        }

        $resolved = [];
        foreach ($outputs as $key => $expression) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($expression) && JsonPathLite::isExpression($expression)) {
                $value = JsonPathLite::evaluate($state, $expression);
                $resolved[$key] = $value;
                if (null === $value) {
                    // Même logique que resolveStepInput : un outputs JSONPath à null
                    // est quasi toujours un bug de définition. On warn pour ne pas
                    // laisser le workflow "réussir" silencieusement avec des outputs
                    // vides que l'appelant consommera sans comprendre.
                    $this->logger->warning('Workflow outputs "{key}" → "{path}" résout à null. Typo dans le chemin ?', [
                        'key' => $key,
                        'path' => $expression,
                        'workflow_run_id' => $this->run->getWorkflowRunId(),
                        'workflow_key' => $this->workflow->getWorkflowKey(),
                    ]);
                }
            } else {
                $resolved[$key] = $expression;
            }
        }

        return $resolved;
    }

    private function markFailed(string $reason): void
    {
        $this->run->setStatus(WorkflowRunStatus::FAILED);
        $this->run->setErrorMessage($reason);
        $this->run->setCompletedAt(new \DateTimeImmutable());
    }
}
