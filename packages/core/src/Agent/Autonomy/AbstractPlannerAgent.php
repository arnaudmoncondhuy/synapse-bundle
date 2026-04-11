<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Exception\BudgetExceededException;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalFailedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGoalReachedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePlannerPlanProducedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Classe de base pour un agent **planificateur autonome** (Chantier D).
 *
 * À la différence d'un {@see AbstractAgent} classique qui consomme un Input
 * et produit un Output en un seul tour (éventuellement avec du tool-calling
 * à l'intérieur), un `AbstractPlannerAgent` tourne en boucle :
 *
 *   1. **Plan** : produit un {@see Plan} structuré décrivant les prochaines
 *      étapes à partir du {@see Goal} courant et des observations accumulées.
 *   2. **Act** : exécute le plan (via les `CallAgentTool` disponibles et les
 *      tools classiques du `ToolRegistry`).
 *   3. **Observe** : collecte les résultats de l'exécution.
 *   4. **Evaluate** : compare les observations aux `successCriteria` du Goal.
 *   5. **Replan** ou **Done** : si goal atteint → retour Output ; sinon,
 *      remonte les observations dans le prochain prompt et re-plan.
 *
 * ## Garde-fous
 *
 * - **Iterations max** : hard-limit sur `maxPlanningIterations` du BudgetLimit
 *   du contexte (défaut 3 iterations max).
 * - **Budget tokens/coût/temps** : vérifiés avant chaque iteration. Lève
 *   {@see BudgetExceededException} et termine proprement.
 * - **Profondeur** : héritée de `AgentContext::$maxDepth`. Un planner ne peut
 *   pas déclencher d'autres planners en cascade sans exploser la profondeur.
 *
 * ## Scope livré (Chantier D — partial)
 *
 * Cette première implémentation **écrit les plans en sortie** mais ne les
 * exécute pas encore via `MultiAgent`. Elle renvoie le dernier plan généré
 * comme `Output::$data`. L'exécution réelle multi-tour avec boucle observe-
 * plan-replan est la prochaine phase de Chantier D, à valider avec
 * l'utilisateur avant de plonger dedans.
 *
 * Ce choix permet de livrer le squelette complet (VOs, registry, abstract
 * class, preset doc) cette nuit et de discuter du comportement dynamique
 * demain matin.
 *
 * ## Sous-classes concrètes
 *
 * Un planner concret doit :
 * - Définir son `getName()` et `getDescription()`
 * - Définir son `getPresetKey()` (preset supportant response_schema)
 * - Retourner son `Goal` depuis `buildInitialGoal(Input)` ou laisser l'input
 *   le fournir via `$structured['goal']`
 * - Optionnellement override `buildExtraSystemPromptSection()` pour enrichir
 *   le prompt système
 *
 * Voir {@see DemoPlannerAgent} pour un exemple.
 */
abstract class AbstractPlannerAgent extends AbstractAgent
{
    protected readonly LoggerInterface $logger;

    /**
     * Injection via AutowireLocator pour casser la dépendance circulaire :
     * AbstractPlannerAgent (tagué `synapse.agent`) → AgentAsToolRegistry →
     * CodeAgentRegistry → tous les `synapse.agent` → AbstractPlannerAgent.
     *
     * Avec un ServiceLocator, DI ne tente pas de construire AgentAsToolRegistry
     * au moment de construire le planner — la résolution est différée à
     * `$this->getAgentAsToolRegistry()` appelé dans `execute()`, moment où
     * tous les agents sont déjà instanciés dans CodeAgentRegistry.
     *
     * Idem pour `WorkflowRunner` (indirectement : il dépend de `AgentResolver`
     * qui dépend des agents qui dépendent du planner).
     */
    public function __construct(
        protected readonly ChatService $chatService,
        #[AutowireLocator([AgentAsToolRegistry::class, WorkflowRunner::class])]
        private readonly ContainerInterface $autonomyServicesLocator,
        protected readonly EntityManagerInterface $entityManager,
        ?LoggerInterface $logger = null,
        protected readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    protected function getAgentAsToolRegistry(): AgentAsToolRegistry
    {
        /* @var AgentAsToolRegistry */
        return $this->autonomyServicesLocator->get(AgentAsToolRegistry::class);
    }

    protected function getWorkflowRunner(): WorkflowRunner
    {
        /* @var WorkflowRunner */
        return $this->autonomyServicesLocator->get(WorkflowRunner::class);
    }

    /**
     * Chaque concret doit retourner le Goal poursuivi — soit construit à partir
     * de l'Input, soit extrait de `$input->getStructured()['goal']` si le caller
     * l'a fourni, soit un Goal constant pour les planners mono-tâche.
     */
    abstract protected function buildInitialGoal(Input $input): Goal;

    /**
     * Section libre du prompt système spécifique au planner concret
     * (par exemple : liste de domaines, style de raisonnement, restrictions).
     * Retournée sous `getSystemPrompt()`, ajoutée après le bloc d'instructions
     * standard.
     */
    protected function buildExtraSystemPromptSection(): string
    {
        return '';
    }

    /**
     * Limite par défaut d'iterations de replan pour ce planner. Override pour
     * customiser. Intègre le BudgetLimit du contexte au moment du run —
     * la valeur la plus stricte gagne.
     */
    protected function defaultMaxIterations(): int
    {
        return 3;
    }

    public function useMasterPrompt(): bool
    {
        return false; // Les planners ont leur propre prompt autonome.
    }

    public function getSystemPrompt(): string
    {
        $base = <<<'PROMPT'
Tu es un agent planificateur autonome. Ton rôle n'est pas de répondre directement à l'utilisateur, mais de produire un **plan structuré** décrivant les étapes nécessaires pour atteindre l'objectif qui t'est donné.

## Format de sortie obligatoire

Tu dois répondre en JSON strict conforme au schéma :

```
{
  "reasoning": "Phrase courte expliquant ta stratégie globale",
  "steps": [
    {
      "name": "nom_unique_du_step",
      "agent_name": "clé_de_l_agent_à_appeler",
      "input_mapping": {"message": "$.inputs.topic"},
      "output_key": "resultat",
      "rationale": "Pourquoi ce step est nécessaire"
    }
  ],
  "outputs": {
    "reponse_finale": "$.steps.nom_du_dernier_step.output.text"
  }
}
```

## Règles

1. Tu dois UNIQUEMENT utiliser les agents que tu connais comme étant disponibles. Ne pas inventer d'agents.
2. Les `input_mapping` utilisent le format pivot `$.inputs.KEY` ou `$.steps.NAME.output.text`.
3. Chaque step doit avoir un `rationale` — explique *pourquoi* il est nécessaire.
4. Décompose en étapes minimales : préfère plus d'étapes simples que moins d'étapes complexes.
5. Si l'objectif est simple et nécessite un seul agent, produis un plan à une seule étape — c'est normal.
PROMPT;

        $extra = $this->buildExtraSystemPromptSection();
        if ('' !== $extra) {
            return $base."\n\n## Spécifique à cet agent\n\n".$extra;
        }

        return $base;
    }

    /**
     * {@inheritdoc}
     *
     * Implémente la logique planner complète avec boucle observe-plan-replan
     * (Chantier D phase 3). Pseudo-code :
     *
     *   iteration = 0
     *   observations = null
     *   while iteration < maxIterations:
     *       enforceBudget()
     *       plan = callLLM(goal, observations, iteration)
     *       if plan.isEmpty(): break
     *       dispatchPlanEvent(plan)
     *       result = executeViaWorkflowRunner(plan)
     *       observations = summarize(result)
     *       if isGoalReached(goal, result): break
     *       iteration++
     *   return aggregateOutput(plan, result, allUsage)
     *
     * Le critère d'arrêt par défaut est soit :
     * - Le dernier run a réussi sans erreur (cas trivial : 1 iteration, pas de replan)
     * - Le Goal porte des `successCriteria` et le LLM s'auto-évalue comme "goal atteint"
     * - `maxPlanningIterations` atteint (garde-fou)
     *
     * Les sous-classes peuvent override {@see shouldReplan()} pour injecter
     * leur propre logique d'évaluation.
     */
    protected function execute(Input $input, AgentContext $context): Output
    {
        $goal = $this->resolveGoal($input, $context);

        $maxIterations = $this->resolveMaxIterations($context);
        $iteration = 0;
        $cumulativeUsage = [];
        $previousObservations = null;
        $lastPlan = null;
        $lastRunOutput = null;
        $lastEphemeralKey = null;
        $lastPlanDebugId = null;
        $allEphemeralKeys = [];
        // Chantier D + Principe 8 : tracker l'issue pour n'émettre GoalFailed
        // que dans les cas où GoalReached n'a PAS déjà été dispatché plus haut.
        $goalReached = false;

        while ($iteration < $maxIterations) {
            $this->enforceBudget($context, sprintf('before_plan_iteration_%d', $iteration));

            // ── 1. Planifier (LLM call structured output) ──
            [$plan, $planResult] = $this->planOneIteration($input, $goal, $context, $iteration, $previousObservations);

            if (null === $plan) {
                // Parsing/validation du plan échoué → retour erreur immédiat
                // avec les usages cumulés jusqu'ici.
                $cumulativeUsage = $this->mergeUsage(
                    $cumulativeUsage,
                    is_array($planResult['usage'] ?? null) ? $planResult['usage'] : [],
                );

                return new Output(
                    answer: sprintf(
                        'Planner failed to produce a valid plan at iteration %d. Raw answer: %s',
                        $iteration,
                        mb_substr((string) ($planResult['answer'] ?? ''), 0, 200),
                    ),
                    data: [
                        'error' => 'no_plan',
                        'iteration' => $iteration,
                        'goal' => $goal->toArray(),
                        'raw_answer' => $planResult['answer'] ?? '',
                    ],
                    usage: $cumulativeUsage,
                );
            }

            $lastPlan = $plan;
            $cumulativeUsage = $this->mergeUsage(
                $cumulativeUsage,
                is_array($planResult['usage'] ?? null) ? $planResult['usage'] : [],
            );
            $lastPlanDebugId = is_string($planResult['debug_id'] ?? null) ? $planResult['debug_id'] : $lastPlanDebugId;

            // ── 2. Cas limite : plan vide ──
            if (0 === $plan->stepsCount()) {
                // Si c'est la 1re iteration et le planner dit "rien à faire",
                // on s'arrête proprement. Si on est en replan et le planner
                // conclut que plus rien n'est faisable, idem : stop.
                $this->logger->info('Planner {name} produced empty plan at iteration {iter}, stopping', [
                    'name' => $this->getName(),
                    'iter' => $iteration,
                ]);

                break;
            }

            // ── 3. Persister comme éphémère + dispatcher l'event ──
            try {
                $ephemeralWorkflow = $this->persistPlanAsEphemeralWorkflow($plan, $goal);
                $lastEphemeralKey = $ephemeralWorkflow->getWorkflowKey();
                $allEphemeralKeys[] = $lastEphemeralKey;

                $this->eventDispatcher?->dispatch(new SynapsePlannerPlanProducedEvent(
                    plannerName: $this->getName(),
                    goal: $goal,
                    plan: $plan,
                    workflowRunId: null,
                    ephemeralWorkflowKey: $lastEphemeralKey,
                ));

                // ── 4. Exécuter le plan via WorkflowRunner ──
                $runOutput = $this->getWorkflowRunner()->run(
                    $ephemeralWorkflow,
                    Input::ofStructured($this->collectInitialInputs($input, $goal)),
                    ['context' => $context],
                );

                $lastRunOutput = $runOutput;
                $cumulativeUsage = $this->mergeUsage($cumulativeUsage, $runOutput->getUsage());

                // ── 5. Évaluer si le goal est atteint ──
                if (!$this->shouldReplan($goal, $plan, $runOutput, $iteration, $maxIterations)) {
                    // Goal atteint ou raisons internes de ne pas replanifier : sortie.
                    // Chantier D + Principe 8 : dispatch SynapseGoalReachedEvent
                    // pour que la sidebar bascule le goal en état « atteint ».
                    $goalReached = true;
                    $this->eventDispatcher?->dispatch(new SynapseGoalReachedEvent(
                        plannerName: $this->getName(),
                        goal: $goal,
                        iterations: $iteration + 1,
                        totalUsage: $cumulativeUsage,
                    ));

                    break;
                }

                // ── 6. Construire les observations pour la prochaine iteration ──
                $previousObservations = $this->buildObservationsFromRun($plan, $runOutput, null);
                $this->logger->info('Planner {name} requesting replan after iteration {iter}', [
                    'name' => $this->getName(),
                    'iter' => $iteration,
                ]);
            } catch (BudgetExceededException $e) {
                // Budget dépassé : remonte l'exception pour que le caller voie
                // clairement que l'agent s'est arrêté sur un hard limit.
                // Chantier D + Principe 8 : signaler l'échec à la sidebar avant
                // de rethrow pour que le goal courant bascule en rouge.
                $this->eventDispatcher?->dispatch(new SynapseGoalFailedEvent(
                    plannerName: $this->getName(),
                    goal: $goal,
                    iterations: $iteration + 1,
                    reason: 'budget_exceeded',
                    errorMessage: $e->getMessage(),
                    totalUsage: $cumulativeUsage,
                ));

                throw $e;
            } catch (\Throwable $e) {
                // Exécution échouée → au lieu de sortir, on capture l'erreur et
                // on tente un replan (jusqu'à maxIterations) avec les
                // observations incluant le message d'erreur. C'est le vrai
                // pattern observe-plan-replan : apprendre de ses échecs.
                $this->logger->warning('Planner {name} iteration {iter} failed: {message}', [
                    'name' => $this->getName(),
                    'iter' => $iteration,
                    'message' => $e->getMessage(),
                ]);

                $previousObservations = $this->buildObservationsFromRun(
                    $plan,
                    null,
                    $e->getMessage(),
                );

                // Si c'est la dernière iteration autorisée, on remonte l'erreur
                // comme answer sans retenter.
                if ($iteration + 1 >= $maxIterations) {
                    // Chantier D + Principe 8 : GoalFailedEvent avec
                    // reason=execution_failed avant de retourner le Output
                    // d'échec — la sidebar bascule le goal en rouge.
                    $this->eventDispatcher?->dispatch(new SynapseGoalFailedEvent(
                        plannerName: $this->getName(),
                        goal: $goal,
                        iterations: $iteration + 1,
                        reason: 'execution_failed',
                        errorMessage: $e->getMessage(),
                        totalUsage: $cumulativeUsage,
                    ));

                    return new Output(
                        answer: sprintf(
                            "Plan échoué après %d itération(s) : %s\n\nDernier plan :\n%s",
                            $iteration + 1,
                            $e->getMessage(),
                            $plan->reasoning,
                        ),
                        data: [
                            'error' => 'execution_failed_after_replans',
                            'iterations' => $iteration + 1,
                            'max_iterations' => $maxIterations,
                            'message' => $e->getMessage(),
                            'goal' => $goal->toArray(),
                            'last_plan' => $plan->toArray(),
                            'ephemeral_workflow_keys' => $allEphemeralKeys,
                        ],
                        usage: $cumulativeUsage,
                        debugId: $lastPlanDebugId,
                    );
                }

                // Sinon on continue pour un replan avec observations d'erreur
            }

            ++$iteration;
        }

        // ── Sortie finale (succès, plan vide, ou max iterations atteint) ──

        if (null === $lastPlan) {
            // N'est jamais atteint en théorie (on a toujours au moins 1 iter)
            return new Output(
                answer: 'Planner stopped without producing any plan.',
                data: ['error' => 'no_plan_at_all', 'goal' => $goal->toArray()],
                usage: $cumulativeUsage,
            );
        }

        if (0 === $lastPlan->stepsCount()) {
            // Principe 8 : plan vide final = goal non atteint (le planner a
            // renoncé sciemment). Signaler à la sidebar.
            if (!$goalReached) {
                $this->eventDispatcher?->dispatch(new SynapseGoalFailedEvent(
                    plannerName: $this->getName(),
                    goal: $goal,
                    iterations: $iteration + 1,
                    reason: 'empty_plan',
                    errorMessage: $lastPlan->reasoning,
                    totalUsage: $cumulativeUsage,
                ));
            }

            return new Output(
                answer: sprintf(
                    "Le planner n'a rien à exécuter : %s",
                    $lastPlan->reasoning,
                ),
                data: [
                    'goal' => $goal->toArray(),
                    'plan' => $lastPlan->toArray(),
                    'iterations' => $iteration + 1,
                    'callable_agents_available' => array_keys($this->getAgentAsToolRegistry()->getCallableAgents()),
                ],
                usage: $cumulativeUsage,
                debugId: $lastPlanDebugId,
            );
        }

        // Principe 8 : le while a atteint maxIterations sans break → échec
        // par épuisement du budget d'itérations. Si GoalReached a déjà été
        // dispatché, on skip (évite un double event contradictoire).
        if (!$goalReached && $iteration >= $maxIterations) {
            $this->eventDispatcher?->dispatch(new SynapseGoalFailedEvent(
                plannerName: $this->getName(),
                goal: $goal,
                iterations: $iteration,
                reason: 'max_iterations',
                errorMessage: sprintf('Goal non atteint après %d itérations', $maxIterations),
                totalUsage: $cumulativeUsage,
            ));
        }

        $this->logger->info('Planner {name} completed: {iters} iteration(s), {tokens} total tokens', [
            'name' => $this->getName(),
            'iters' => $iteration + 1,
            'tokens' => $cumulativeUsage['total_tokens'] ?? 0,
        ]);

        return new Output(
            answer: $lastRunOutput?->getAnswer() ?? sprintf(
                'Plan exécuté (%d étape(s), %d itération(s)) : %s',
                $lastPlan->stepsCount(),
                $iteration + 1,
                $lastPlan->reasoning,
            ),
            data: [
                'goal' => $goal->toArray(),
                'plan' => $lastPlan->toArray(),
                'iterations' => $iteration + 1,
                'max_iterations' => $maxIterations,
                'workflow_run_id' => $lastRunOutput?->getMetadata()['workflow_run_id'] ?? null,
                'workflow_key' => $lastEphemeralKey,
                'ephemeral_workflow_keys' => $allEphemeralKeys,
                'step_outputs' => $lastRunOutput?->getData() ?? [],
                'callable_agents_available' => array_keys($this->getAgentAsToolRegistry()->getCallableAgents()),
            ],
            usage: $cumulativeUsage,
            debugId: $lastPlanDebugId,
            generatedAttachments: $lastRunOutput?->getGeneratedAttachments() ?? [],
        );
    }

    /**
     * Une iteration de planification : un seul LLM call qui produit un Plan
     * (ou null si parsing échoue). Extrait pour être réutilisable par la
     * boucle `execute()` et potentiellement par des sous-classes qui
     * voudraient override la stratégie de planning sans toucher à la boucle.
     *
     * @return array{0: Plan|null, 1: array<string, mixed>}
     */
    protected function planOneIteration(
        Input $input,
        Goal $goal,
        AgentContext $context,
        int $iteration,
        ?string $previousObservations,
    ): array {
        $callableAgents = array_values(array_map(
            static fn ($a) => sprintf('- **%s** : %s', $a->getName(), $a->getDescription()),
            $this->getAgentAsToolRegistry()->getCallableAgents(),
        ));

        $userPrompt = $this->buildUserPromptForPlanning($goal, $input, $callableAgents, $iteration, $previousObservations);

        $this->logger->info('Planner {name} iteration {iter}: calling LLM', [
            'name' => $this->getName(),
            'iter' => $iteration,
            'goal' => $goal->description,
            'hasObservations' => null !== $previousObservations,
        ]);

        $result = $this->chatService->ask(
            $userPrompt,
            $this->buildAskOptions([
                'stateless' => true,
                'module' => 'autonomy',
                'action' => sprintf('plan_iter_%d', $iteration),
                'response_format' => PlanResponseSchema::schema(),
                'tools' => [],
                'context' => $context,
            ]),
        );

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured)) {
            $structured = $this->tryExtractPlanJson($result['answer'] ?? '');
        }

        if (!is_array($structured)) {
            return [null, $result];
        }

        try {
            $plan = Plan::fromArray($structured, iteration: $iteration);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Planner {name} iteration {iter}: invalid plan structure — {message}', [
                'name' => $this->getName(),
                'iter' => $iteration,
                'message' => $e->getMessage(),
            ]);

            return [null, $result];
        }

        return [$plan, $result];
    }

    /**
     * Décide si une nouvelle iteration de replan est nécessaire après un run
     * réussi. Par défaut :
     *
     * - Si `goal->successCriteria` est vide → **false** (pas de critère, on
     *   considère la 1re iteration réussie comme suffisante — pragmatique)
     * - Sinon **false** aussi pour l'instant (le LLM-as-judge pour auto-évaluer
     *   les critères est out of scope de cette phase, viendra en phase 4)
     *
     * Les sous-classes peuvent override pour injecter leur propre logique.
     * Ex: un planner de recherche peut re-planifier tant que le nombre de
     * résultats trouvés < seuil cible.
     */
    protected function shouldReplan(Goal $goal, Plan $plan, Output $runOutput, int $iteration, int $maxIterations): bool
    {
        // Phase 3 : pas d'auto-évaluation LLM, on fait confiance à la 1re
        // iteration réussie. Si le goal a des critères, ils sont passés au
        // LLM dans le prompt système pour que **lui** en tienne compte lors
        // de la planification, mais on ne re-vérifie pas après coup.
        //
        // Le replan automatique ne se déclenche donc que sur **échec
        // d'exécution**, qui est géré directement dans la boucle `execute()`
        // via le catch sur Throwable.
        return false;
    }

    /**
     * Résout le nombre max d'iterations depuis le BudgetLimit du contexte
     * s'il en a un, sinon retombe sur {@see defaultMaxIterations()}.
     */
    protected function resolveMaxIterations(AgentContext $context): int
    {
        $budget = $context->getBudget();
        if (null !== $budget && null !== $budget->maxPlanningIterations) {
            return max(1, $budget->maxPlanningIterations);
        }

        return max(1, $this->defaultMaxIterations());
    }

    /**
     * Construit un résumé textuel court (< 1500 chars) de ce qui vient de se
     * passer, à injecter dans le prompt utilisateur de la prochaine iteration
     * pour que le LLM puisse ajuster son plan en conséquence.
     *
     * Format attendu par le LLM : texte libre en français structuré avec des
     * sections claires (Ce que le plan a fait / Résultats observés / Échec
     * éventuel / Ce qu'il faut changer).
     */
    protected function buildObservationsFromRun(
        Plan $previousPlan,
        ?Output $runOutput,
        ?string $errorMessage,
    ): string {
        $lines = [];

        if (null !== $errorMessage) {
            $lines[] = '## Résultat : ÉCHEC';
            $lines[] = '';
            $lines[] = 'L\'exécution du plan précédent a échoué avec le message :';
            $lines[] = '```';
            $lines[] = mb_substr($errorMessage, 0, 500);
            $lines[] = '```';
            $lines[] = '';
            $lines[] = 'Analyse la cause de l\'échec et propose un plan alternatif qui évite ce problème. Tu peux choisir des agents différents, changer l\'ordre des steps, ou adapter les input_mapping.';

            return implode("\n", $lines);
        }

        if (null !== $runOutput) {
            $lines[] = '## Résultat : SUCCÈS';
            $lines[] = '';
            $lines[] = sprintf('Le plan précédent a exécuté ses %d step(s) sans erreur.', $previousPlan->stepsCount());
            $lines[] = '';

            $data = $runOutput->getData();
            if ([] !== $data) {
                $lines[] = '### Outputs produits';
                $lines[] = '';
                foreach ($data as $key => $value) {
                    if (!is_string($value) || '' === trim($value)) {
                        continue;
                    }
                    $preview = mb_substr($value, 0, 300);
                    if (mb_strlen($value) > 300) {
                        $preview .= '…';
                    }
                    $lines[] = sprintf('**%s** :', (string) $key);
                    $lines[] = $preview;
                    $lines[] = '';
                }
            }

            $lines[] = 'Analyse si ces outputs satisfont le goal. Si oui, produis un plan vide (steps: []) pour signaler que c\'est terminé. Si non, produis un nouveau plan qui complète ou corrige les outputs existants.';

            return implode("\n", $lines);
        }

        return 'Aucune observation disponible. Plan le goal comme si c\'était la première iteration.';
    }

    /**
     * Persiste un Plan comme SynapseWorkflow éphémère, immédiatement exécutable.
     *
     * Le workflow généré porte :
     * - `workflowKey` unique : `planner_<requestId>_<timestamp>`
     * - `isEphemeral = true` + `retentionUntil` hérité de synapse.ephemeral.retention_days
     *   (via la même convention que les autres créations éphémères Chantier A)
     * - `isActive = true` (exécutable immédiatement par le runner)
     * - La définition pivot du plan (steps + outputs)
     */
    protected function persistPlanAsEphemeralWorkflow(Plan $plan, Goal $goal): SynapseWorkflow
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey(sprintf('planner_%s_%d', $this->getName(), time()));
        $workflow->setName(sprintf('Plan généré par %s', $this->getLabel()));
        $workflow->setDescription(sprintf(
            'Plan éphémère produit par %s pour : %s',
            $this->getName(),
            mb_substr($goal->description, 0, 150),
        ));
        $workflow->setDefinition($plan->toWorkflowDefinition());
        $workflow->setIsBuiltin(false);
        $workflow->setIsActive(true);
        $workflow->setIsEphemeral(true);
        // retention_until est géré par WorkflowRunner → MultiAgent → l'hôte décide
        // de la rétention standard. On ne set pas explicitement ici pour laisser
        // la config par défaut s'appliquer.
        $workflow->setRetentionUntil(
            (new \DateTimeImmutable())->modify('+7 days'),
        );

        $this->entityManager->persist($workflow);
        $this->entityManager->flush();

        return $workflow;
    }

    /**
     * Construit le tableau d'inputs passé au WorkflowRunner comme racine.
     * Par défaut, on injecte `message` + `goal` + le contenu structured de l'Input.
     * Sous-classe peut override pour enrichir.
     *
     * @return array<string, mixed>
     */
    protected function collectInitialInputs(Input $input, Goal $goal): array
    {
        $inputs = [
            'message' => $input->getMessage(),
            'goal' => $goal->description,
        ];

        foreach ($input->getStructured() as $key => $value) {
            if (is_string($key)) {
                $inputs[$key] = $value;
            }
        }

        return $inputs;
    }

    /**
     * Agrège deux structures d'usage (format TokenUsage-like array).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, int>
     */
    private function mergeUsage(array $a, array $b): array
    {
        $keys = ['prompt_tokens', 'completion_tokens', 'total_tokens', 'thinking_tokens'];
        $merged = [];
        foreach ($keys as $k) {
            $va = isset($a[$k]) && is_int($a[$k]) ? $a[$k] : 0;
            $vb = isset($b[$k]) && is_int($b[$k]) ? $b[$k] : 0;
            $merged[$k] = $va + $vb;
        }

        return $merged;
    }

    private function resolveGoal(Input $input, AgentContext $context): Goal
    {
        $contextGoal = $context->getGoal();
        if (null !== $contextGoal) {
            return $contextGoal;
        }

        // Extraire depuis l'input structured si fourni
        $structured = $input->getStructured();
        if (isset($structured['goal']) && is_string($structured['goal'])) {
            return Goal::of($structured['goal']);
        }

        return $this->buildInitialGoal($input);
    }

    /**
     * @param array<int, string> $callableAgentsDescriptions
     */
    protected function buildUserPromptForPlanning(Goal $goal, Input $input, array $callableAgentsDescriptions, int $iteration, ?string $previousObservations): string
    {
        $lines = [
            '## '.($iteration > 0 ? sprintf('Replan (iteration #%d)', $iteration) : 'Plan initial'),
            '',
            $goal->toPromptBlock(),
            '',
        ];

        // Chantier D phase 2 : informer le planner des inputs racine qui seront
        // disponibles pour `input_mapping` au runtime. Ce bloc est critique
        // pour que les steps utilisent les bons JSONPath.
        $lines[] = '## Inputs root disponibles';
        $lines[] = '';
        $lines[] = 'Le WorkflowRunner injectera ces inputs à la racine. Tu DOIS les référencer exactement avec ces clés :';
        $lines[] = '';
        $lines[] = '- `$.inputs.message` : le message texte initial de l\'utilisateur (peut être vide si le caller n\'a fourni que du structured)';
        $lines[] = '- `$.inputs.goal` : la description textuelle du goal courant';
        $lines[] = '';
        $lines[] = 'Tu peux aussi référencer la sortie d\'un step précédent via `$.steps.NOM_STEP.output.text`.';
        $lines[] = '';

        if ([] !== $callableAgentsDescriptions) {
            $lines[] = '## Agents disponibles comme étapes du plan';
            $lines[] = '';
            foreach ($callableAgentsDescriptions as $desc) {
                $lines[] = $desc;
            }
            $lines[] = '';
        }

        if (null !== $previousObservations && '' !== $previousObservations) {
            $lines[] = '## Observations de l\'itération précédente';
            $lines[] = '';
            $lines[] = $previousObservations;
            $lines[] = '';
            $lines[] = 'Ajuste ton plan en fonction de ces observations.';
            $lines[] = '';
        }

        $message = $input->getMessage();
        if ('' !== trim($message)) {
            $lines[] = '## Contexte utilisateur';
            $lines[] = '';
            $lines[] = $message;
            $lines[] = '';
        }

        $lines[] = 'Produis maintenant le plan au format JSON structuré demandé.';

        return implode("\n", $lines);
    }

    /**
     * Vérifie les hard-limits du BudgetLimit du contexte. Lève
     * {@see BudgetExceededException} si dépassé.
     */
    protected function enforceBudget(AgentContext $context, string $checkpoint): void
    {
        $budget = $context->getBudget() ?? BudgetLimit::unlimited();

        $violation = $budget->firstViolation(
            currentDepth: $context->getDepth(),
            elapsedSeconds: $context->getElapsedSeconds(),
        );

        if (null !== $violation) {
            throw new BudgetExceededException(violationDetail: sprintf('[%s] %s', $checkpoint, $violation), budget: $budget, currentDepth: $context->getDepth(), elapsedSeconds: $context->getElapsedSeconds());
        }
    }

    /**
     * Extraction best-effort d'un Plan JSON depuis un texte libre.
     * Cherche le premier bloc ```...``` ou le premier objet {...} du texte.
     *
     * @return array<string, mixed>|null
     */
    private function tryExtractPlanJson(string $text): ?array
    {
        if ('' === $text) {
            return null;
        }

        // Code fence ```json ... ``` ou ``` ... ```
        if (1 === preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);

            return is_array($decoded) ? $decoded : null;
        }

        // Sinon, premier { au dernier } — heuristique basique
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if (false !== $firstBrace && false !== $lastBrace && $lastBrace > $firstBrace) {
            $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($candidate, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
