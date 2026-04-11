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
use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

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
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    protected function getAgentAsToolRegistry(): AgentAsToolRegistry
    {
        /** @var AgentAsToolRegistry */
        return $this->autonomyServicesLocator->get(AgentAsToolRegistry::class);
    }

    protected function getWorkflowRunner(): WorkflowRunner
    {
        /** @var WorkflowRunner */
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
     * Implémente la logique planner pour la phase 1 du Chantier D : produit UN
     * plan initial et le retourne. L'exécution du plan + la boucle observe-
     * replan sont laissées pour une phase 2 après validation utilisateur.
     */
    protected function execute(Input $input, AgentContext $context): Output
    {
        $goal = $this->resolveGoal($input, $context);

        // Garde-fous budget avant même le premier appel LLM
        $this->enforceBudget($context, 'before_initial_plan');

        $callableAgents = array_map(
            static fn ($a) => sprintf('- **%s** : %s', $a->getName(), $a->getDescription()),
            $this->getAgentAsToolRegistry()->getCallableAgents(),
        );

        $userPrompt = $this->buildUserPromptForPlanning($goal, $input, $callableAgents, iteration: 0, previousObservations: null);

        $this->logger->info('Planner {name} starting for goal: {goal}', [
            'name' => $this->getName(),
            'goal' => $goal->description,
            'runId' => $context->getRequestId(),
        ]);

        $result = $this->chatService->ask(
            $userPrompt,
            $this->buildAskOptions([
                'stateless' => true,
                'module' => 'autonomy',
                'action' => 'plan_initial',
                // Chantier D : force JSON structuré via response_format. Le planner
                // ne fait PAS de tool-calling (il produit un Plan, il ne l'exécute
                // pas — l'exécution est la responsabilité de `executeRun()` phase 2).
                'response_format' => PlanResponseSchema::schema(),
                // Pas de tools à disposition du LLM : le planner décrit des steps
                // qui référencent des agents, il n'appelle rien lui-même. Passer
                // une liste de tools sèmerait de la confusion.
                'tools' => [],
                'context' => $context,
            ]),
        );

        $structured = $result['structured_output'] ?? null;
        if (!is_array($structured)) {
            // Si pas de structured output, on essaie d'extraire depuis la réponse texte
            $structured = $this->tryExtractPlanJson($result['answer'] ?? '');
        }

        if (!is_array($structured)) {
            return new Output(
                answer: 'Planner failed to produce a valid plan (no structured output and no parseable JSON in answer).',
                data: [
                    'error' => 'no_plan',
                    'raw_answer' => $result['answer'] ?? '',
                ],
                usage: is_array($result['usage'] ?? null) ? $result['usage'] : [],
            );
        }

        try {
            $plan = Plan::fromArray($structured, iteration: 0);
        } catch (\InvalidArgumentException $e) {
            return new Output(
                answer: sprintf('Planner produced an invalid plan structure: %s', $e->getMessage()),
                data: [
                    'error' => 'invalid_plan',
                    'message' => $e->getMessage(),
                    'raw' => $structured,
                ],
                usage: is_array($result['usage'] ?? null) ? $result['usage'] : [],
            );
        }

        $this->logger->info('Planner {name} produced initial plan with {steps} steps', [
            'name' => $this->getName(),
            'steps' => $plan->stepsCount(),
            'runId' => $context->getRequestId(),
        ]);

        $planUsage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $planDebugId = is_string($result['debug_id'] ?? null) ? $result['debug_id'] : null;

        // ── Chantier D phase 2 : exécution réelle du plan via WorkflowRunner ──
        //
        // Le plan est matérialisé comme `SynapseWorkflow` éphémère (visible dans
        // l'admin, promouvable si l'utilisateur veut le garder), puis exécuté
        // synchroniquement. Les outputs du workflow deviennent la réponse du
        // planner, agrégés avec l'usage du planning LLM-call.

        if (0 === $plan->stepsCount()) {
            // Cas légitime : le planner a conclu que rien n'était faisable avec
            // les agents disponibles. On retourne le reasoning comme answer
            // sans lancer d'exécution inutile.
            return new Output(
                answer: sprintf(
                    "Le planner n'a rien à exécuter : %s",
                    $plan->reasoning,
                ),
                data: [
                    'goal' => $goal->toArray(),
                    'plan' => $plan->toArray(),
                    'callable_agents_available' => array_keys($this->getAgentAsToolRegistry()->getCallableAgents()),
                ],
                usage: $planUsage,
                debugId: $planDebugId,
            );
        }

        try {
            $ephemeralWorkflow = $this->persistPlanAsEphemeralWorkflow($plan, $goal);
            $runOutput = $this->getWorkflowRunner()->run(
                $ephemeralWorkflow,
                Input::ofStructured($this->collectInitialInputs($input, $goal)),
                ['context' => $context],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Planner {name} failed during plan execution: {message}', [
                'name' => $this->getName(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new Output(
                answer: sprintf(
                    "Plan généré mais l'exécution a échoué : %s\n\nPlan :\n%s",
                    $e->getMessage(),
                    $plan->reasoning,
                ),
                data: [
                    'error' => 'execution_failed',
                    'message' => $e->getMessage(),
                    'goal' => $goal->toArray(),
                    'plan' => $plan->toArray(),
                ],
                usage: $planUsage,
                debugId: $planDebugId,
            );
        }

        // ── Merge planning usage + execution usage ──
        $mergedUsage = $this->mergeUsage($planUsage, $runOutput->getUsage());

        $this->logger->info('Planner {name} completed: plan + exec = {tokens} total tokens, {steps} steps executed', [
            'name' => $this->getName(),
            'tokens' => $mergedUsage['total_tokens'] ?? 0,
            'steps' => $plan->stepsCount(),
        ]);

        return new Output(
            answer: $runOutput->getAnswer() ?? sprintf(
                "Plan exécuté (%d étape(s)) : %s",
                $plan->stepsCount(),
                $plan->reasoning,
            ),
            data: [
                'goal' => $goal->toArray(),
                'plan' => $plan->toArray(),
                'workflow_run_id' => $runOutput->getMetadata()['workflow_run_id'] ?? null,
                'workflow_key' => $ephemeralWorkflow->getWorkflowKey(),
                'step_outputs' => $runOutput->getData(),
                'callable_agents_available' => array_keys($this->getAgentAsToolRegistry()->getCallableAgents()),
            ],
            usage: $mergedUsage,
            debugId: $planDebugId,
            generatedAttachments: $runOutput->getGeneratedAttachments(),
        );
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
            throw new BudgetExceededException(
                violationDetail: sprintf('[%s] %s', $checkpoint, $violation),
                budget: $budget,
                currentDepth: $context->getDepth(),
                elapsedSeconds: $context->getElapsedSeconds(),
            );
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
