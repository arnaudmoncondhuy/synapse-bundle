<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Exception\BudgetExceededException;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan;
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
     */
    public function __construct(
        protected readonly ChatService $chatService,
        #[AutowireLocator([AgentAsToolRegistry::class])]
        private readonly ContainerInterface $agentAsToolRegistryLocator,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    protected function getAgentAsToolRegistry(): AgentAsToolRegistry
    {
        /** @var AgentAsToolRegistry */
        return $this->agentAsToolRegistryLocator->get(AgentAsToolRegistry::class);
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

        // Chantier D phase 1 : on retourne le plan sans l'exécuter. L'exécution
        // multi-tour arrive en phase 2 après validation utilisateur du pattern.
        return new Output(
            answer: sprintf(
                "Plan généré (%d étape(s)) :\n\n%s",
                $plan->stepsCount(),
                $plan->reasoning,
            ),
            data: [
                'goal' => $goal->toArray(),
                'plan' => $plan->toArray(),
                'workflow_definition' => $plan->toWorkflowDefinition(),
                'callable_agents_available' => array_keys($this->getAgentAsToolRegistry()->getCallableAgents()),
                'next_phase' => 'Execute this plan via WorkflowRunner + loop back with observations for replan (Chantier D phase 2, deferred pending user review).',
            ],
            usage: is_array($result['usage'] ?? null) ? $result['usage'] : [],
            debugId: is_string($result['debug_id'] ?? null) ? $result['debug_id'] : null,
        );
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
