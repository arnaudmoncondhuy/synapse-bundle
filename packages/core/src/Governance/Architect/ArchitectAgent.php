<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\Architect;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Agent architecte — génère des définitions d'agents ou de workflows via JSON Mode.
 *
 * Phase 11 du plan de priorité 2. Cet agent prend une description en langage
 * naturel et produit une proposition structurée (structured output, Phase 6) :
 *   - `create_agent` : définition complète d'un nouvel agent (clé, nom, prompt…)
 *   - `improve_prompt` : nouveau prompt amélioré pour un agent existant
 *   - `create_workflow` : définition d'un workflow (clé, nom, steps…)
 *
 * La proposition est retournée dans {@see Output::$data}. Le caller
 * (commande CLI `synapse:architect`, outil MCP, ou un autre agent) décide
 * ensuite de l'appliquer via {@see ArchitectProposalProcessor} — qui gère
 * la création en mode HITL (inactif / pending) et le scoring LLM-as-Judge.
 *
 * ## Sélection du modèle
 *
 * L'architecte utilise un preset dédié (`synapse.governance.architect_preset_key`).
 * Ce preset doit pointer vers un modèle **supportant les structured outputs**
 * (`supportsResponseSchema = true`). Si la clé est vide ou le preset introuvable,
 * l'agent retourne une erreur claire plutôt qu'un résultat dégradé.
 *
 * ## Sécurité
 *
 * L'architecte ne persiste rien lui-même — il est « read-only » du point de vue
 * BDD. La persistance (et donc le HITL, le scoring, l'audit trail) est la
 * responsabilité du {@see ArchitectProposalProcessor}. Cette séparation rend
 * l'agent testable indépendamment de la couche persistence.
 */
class ArchitectAgent implements AgentInterface
{
    /** Nombre max de retries avec feedback quand la proposition échoue à la validation. */
    private const MAX_VALIDATION_RETRIES = 2;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly WorkflowDefinitionValidator $workflowValidator,
        #[Autowire('%synapse.governance.architect_preset_key%')]
        private readonly string $architectPresetKey = '',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'architect';
    }

    public function getLabel(): string
    {
        return 'Architecte IA';
    }

    public function getDescription(): string
    {
        return 'Génère des définitions d\'agents ou de workflows à partir d\'une description en langage naturel.';
    }

    /**
     * Entrée attendue :
     *
     * Via `Input::ofStructured()` :
     *   - `action` (string, requis) : `create_agent`, `improve_prompt`, `create_workflow`
     *   - `description` (string, requis) : description en langage naturel de ce que l'on veut
     *   - `agent_key` (string, requis pour `improve_prompt`) : clé de l'agent cible
     *   - `instructions` (string, optionnel) : directives spécifiques pour la modification
     *
     * Sortie :
     *   `Output::ofData()` avec la proposition structurée complète (schéma selon l'action).
     *   En cas d'erreur, `Output::ofData(['error' => '...'])`.
     *
     * @param array<string, mixed> $options `context` (AgentContext, optionnel)
     */
    public function call(Input $input, array $options = []): Output
    {
        $structured = $input->getStructured();

        $action = $structured['action'] ?? null;
        if (!is_string($action) || '' === $action) {
            return Output::ofData(['error' => 'Clé "action" manquante dans l\'input structuré. Actions valides : create_agent, improve_prompt, create_workflow.']);
        }

        $description = $structured['description'] ?? $input->getMessage();
        if ('' === trim((string) $description)) {
            return Output::ofData(['error' => 'Description vide — fournissez une description de ce que vous souhaitez créer ou améliorer.']);
        }

        $preset = $this->resolvePreset();
        if (null === $preset) {
            return Output::ofData(['error' => sprintf(
                'Preset architecte introuvable (clé : "%s"). Configurez synapse.governance.architect_preset_key avec un preset supportant les structured outputs.',
                $this->architectPresetKey,
            )]);
        }

        try {
            $schema = ArchitectResponseSchema::forAction($action);
        } catch (\InvalidArgumentException $e) {
            return Output::ofData(['error' => $e->getMessage()]);
        }

        $prompt = $this->buildPrompt($action, (string) $description, $structured);
        if (null === $prompt) {
            return Output::ofData(['error' => 'Impossible de construire le prompt — vérifiez les paramètres fournis.']);
        }

        // Boucle retry-with-feedback : si la proposition échoue à la
        // validation (seulement pour create_workflow qui a une validation
        // structurelle forte), on rappelle le LLM avec le message d'erreur
        // et la dernière tentative. Gemini Flash Lite oublie régulièrement
        // des champs obligatoires sur les types conditional/parallel/loop,
        // ce retry corrige le tir en 1-2 itérations sans intervention user.
        $currentPrompt = $prompt;
        $totalUsage = [];
        $attempts = 0;
        $maxAttempts = 1 + self::MAX_VALIDATION_RETRIES;
        $lastProposal = null;
        $lastResult = null;
        $lastValidationError = null;

        $lastLlmError = null;
        while ($attempts < $maxAttempts) {
            ++$attempts;

            try {
                $lastResult = $this->chatService->ask(
                    message: $currentPrompt,
                    options: [
                        'agent' => $this->getName(),
                        'preset' => $preset,
                        'stateless' => true,
                        'module' => 'governance',
                        'action' => 'architect_'.$action.($attempts > 1 ? '_retry' : ''),
                        'response_format' => $schema,
                    ],
                );
                $lastLlmError = null;
            } catch (\Throwable $e) {
                $lastLlmError = $e->getMessage();
                $this->logger->warning('ArchitectAgent: LLM call failed attempt {attempt}/{max} — {message}', [
                    'message' => $lastLlmError,
                    'action' => $action,
                    'attempt' => $attempts,
                    'max' => $maxAttempts,
                ]);

                if ($attempts >= $maxAttempts) {
                    // Plus de retries possibles, on remonte l'erreur
                    return Output::ofData(['error' => 'Appel LLM échoué après '.$attempts.' tentative(s) : '.$lastLlmError]);
                }

                // Retry immédiat : sur exception transitoire (HTTP 400 Gemini
                // « function response parts »), un simple retry sans changer
                // le prompt suffit souvent — c'est côté Gemini le bug.
                continue;
            }

            // Agrège les usages cumulatifs sur les retries
            $usage = is_array($lastResult['usage'] ?? null) ? $lastResult['usage'] : [];
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'thinking_tokens'] as $k) {
                if (isset($usage[$k]) && is_int($usage[$k])) {
                    $totalUsage[$k] = ($totalUsage[$k] ?? 0) + $usage[$k];
                }
            }

            $lastProposal = $lastResult['structured_output'] ?? null;
            if (!is_array($lastProposal)) {
                // Si même le structured_output est manquant, retry ne sert à rien
                return Output::ofData(['error' => 'Le LLM n\'a pas retourné de structured output.']);
            }

            // Validation préflight — seulement pour create_workflow.
            // Les autres actions (create_agent, improve_prompt) n'ont pas
            // de validation structurelle équivalente côté Synapse.
            if ('create_workflow' !== $action) {
                break;
            }

            $lastValidationError = $this->preflightValidateWorkflow($lastProposal);
            if (null === $lastValidationError) {
                // Proposition valide, sortie de la boucle retry.
                break;
            }

            $this->logger->info('ArchitectAgent: proposition invalide à la tentative {attempt}, retry — {error}', [
                'attempt' => $attempts,
                'error' => $lastValidationError,
            ]);

            if ($attempts >= $maxAttempts) {
                // Max retries atteint, on sort avec la dernière proposition
                // (sera rejetée par le processor avec le même message).
                break;
            }

            // Prépare le prompt de retry avec le feedback
            $currentPrompt = $this->buildRetryPrompt($prompt, $lastProposal, $lastValidationError);
        }

        if (null === $lastProposal || null === $lastResult) {
            return Output::ofData(['error' => 'Aucune proposition générée après '.$attempts.' tentative(s).']);
        }

        $lastProposal['_action'] = $action;
        $lastProposal['_debug_id'] = $lastResult['debug_id'] ?? null;
        $lastProposal['_attempts'] = $attempts;

        return new Output(
            answer: $lastResult['answer'] ?? null,
            data: $lastProposal,
            usage: $totalUsage,
            debugId: is_string($lastResult['debug_id'] ?? null) ? $lastResult['debug_id'] : null,
        );
    }

    /**
     * Valide une proposition de workflow avant même de la soumettre au processor.
     * Construit une `definition` temporaire et la passe à WorkflowDefinitionValidator.
     *
     * @param array<string, mixed> $proposal
     */
    private function preflightValidateWorkflow(array $proposal): ?string
    {
        $steps = $proposal['steps'] ?? null;
        if (!is_array($steps) || [] === $steps) {
            return 'Le workflow doit contenir au moins une étape.';
        }

        $definition = [
            'version' => 1,
            'description' => is_string($proposal['description'] ?? null) ? $proposal['description'] : '',
            'steps' => $steps,
        ];

        return $this->workflowValidator->validate($definition);
    }

    /**
     * Construit un prompt de retry qui inclut l'ancienne tentative + le message
     * d'erreur du validator. Le LLM doit re-produire la proposition en
     * corrigeant spécifiquement l'erreur pointée.
     *
     * @param array<string, mixed> $previousAttempt
     */
    private function buildRetryPrompt(string $originalPrompt, array $previousAttempt, string $error): string
    {
        $previousJson = json_encode(
            array_diff_key($previousAttempt, array_flip(['_action', '_debug_id', '_attempts'])),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );

        return <<<RETRY
{$originalPrompt}

---

## ⚠️ Tentative précédente rejetée

Tu as déjà essayé de répondre à cette demande, mais ta proposition a été
rejetée par le validateur avec l'erreur suivante :

**{$error}**

Voici ce que tu avais généré (fautif) :

```json
{$previousJson}
```

Produis une NOUVELLE proposition qui corrige cette erreur précise.
Relis attentivement les règles des types de steps — notamment les
champs OBLIGATOIRES de chaque type — et assure-toi que ton JSON les
contient tous. Ne recopie pas les erreurs de la version précédente.
RETRY;
    }

    private function resolvePreset(): ?SynapseModelPreset
    {
        if ('' === $this->architectPresetKey) {
            $this->logger->warning('ArchitectAgent: architect_preset_key is empty — agent disabled.');

            return null;
        }

        $preset = $this->presetRepository->findOneBy(['key' => $this->architectPresetKey]);
        if (!$preset instanceof SynapseModelPreset) {
            $this->logger->warning('ArchitectAgent: preset "{key}" not found.', ['key' => $this->architectPresetKey]);

            return null;
        }

        return $preset;
    }

    /**
     * Construit le prompt envoyé au LLM selon l'action demandée.
     *
     * @param array<string, mixed> $structured
     */
    private function buildPrompt(string $action, string $description, array $structured): ?string
    {
        return match ($action) {
            'create_agent' => $this->buildCreateAgentPrompt($description),
            'improve_prompt' => $this->buildImprovePromptPrompt($description, $structured),
            'create_workflow' => $this->buildCreateWorkflowPrompt($description),
            default => null,
        };
    }

    private function buildCreateAgentPrompt(string $description): string
    {
        return <<<PROMPT
Tu es un expert en ingénierie de prompts pour des agents LLM.

## Ta mission

Concevoir un agent IA complet à partir de la description suivante.

## Description demandée

{$description}

## Règles de conception

1. **Clé (`key`)** : slug en minuscules, underscores autorisés (ex: `support_technique`), entre 3 et 50 caractères.
2. **Nom** : lisible en français, court (< 100 caractères).
3. **Emoji** : un seul emoji représentatif.
4. **Description** : 1-2 phrases résumant l'objectif de l'agent.
5. **System prompt** : c'est le cœur de l'agent. Il doit être :
   - **Structuré** : sections claires avec des titres markdown
   - **Spécifique** : scope bien défini, cas limites couverts
   - **Sécurisé** : interdire explicitement les sorties du périmètre
   - **Professionnel** : ton adapté au contexte de l'agent
   - Longueur typique : 200-800 mots selon la complexité

6. **Allowed tools (optionnel)** : si la mission de l'agent bénéficie d'un
   outil spécifique, déclare-le dans `allowed_tools`. Outils disponibles :
   - `code_execute` : exécute du Python dans un sandbox isolé. Utile pour
     tout ce qui implique des calculs non-triviaux, du parsing de données
     tabulaires (CSV/JSON), des regex complexes, ou quand écrire un script
     est plus fiable que raisonner le résultat. Mentionne-le aussi dans le
     system prompt avec une instruction du type « utilise `code_execute`
     pour tes calculs plutôt que de deviner ».
   - `propose_to_remember` : propose de mémoriser un fait sur l'utilisateur
     (préférence, contrainte, contexte personnel). À réserver aux agents
     conversationnels qui parlent à un humain.
   Si la mission est purement conversationnelle ou rédactionnelle, omets
   ce champ — l'agent aura accès à tous les outils par défaut, ce qui est
   OK aussi.

7. **Reasoning** : explique tes choix de conception (prompt, allowed_tools).

Réponds uniquement en JSON conforme au schéma fourni.
PROMPT;
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function buildImprovePromptPrompt(string $description, array $structured): ?string
    {
        $agentKey = $structured['agent_key'] ?? null;
        if (!is_string($agentKey) || '' === $agentKey) {
            return null;
        }

        $agent = $this->agentRepository->findByKey($agentKey);
        if (null === $agent) {
            return null;
        }

        $currentPrompt = $agent->getSystemPrompt();
        $agentInfo = $this->formatAgentContext($agent);

        $instructions = '';
        $instructionsRaw = $structured['instructions'] ?? null;
        if (is_string($instructionsRaw) && '' !== trim($instructionsRaw)) {
            $instructions = "\n\n## Instructions spécifiques\n\n".$instructionsRaw;
        }

        return <<<PROMPT
Tu es un expert en ingénierie de prompts pour des agents LLM.

## Ta mission

Améliorer le system prompt de l'agent décrit ci-dessous.

## Agent ciblé

{$agentInfo}

## Prompt actuel

{$currentPrompt}

## Demande d'amélioration

{$description}{$instructions}

## Règles

1. Retourne le prompt **complet** (remplacement intégral, pas un diff).
2. Conserve les éléments fonctionnels du prompt existant sauf si la demande dit explicitement de les retirer.
3. Améliore la clarté, la spécificité, la sécurité et la cohérence.
4. Le résumé des changements doit être concis (2-5 phrases) et factuel.
5. Le reasoning doit justifier chaque modification.

Réponds uniquement en JSON conforme au schéma fourni.
PROMPT;
    }

    private function buildCreateWorkflowPrompt(string $description): string
    {
        return <<<PROMPT
Tu es un expert en orchestration de workflows multi-agents.

## Ta mission

Concevoir un workflow à partir de la description suivante.

## Description demandée

{$description}

## Règles de conception

1. **Clé (`key`)** : slug en minuscules, tirets et underscores autorisés (ex: `document_analysis`), entre 3 et 50 caractères.
2. **Nom** : lisible en français, court (< 100 caractères).
3. **Description** : 1-3 phrases résumant ce que fait le workflow.
4. **Steps** : liste ordonnée des étapes. Chaque étape a un `name` unique et un `type` parmi 5 valeurs. Choisis le type en fonction de la nature de l'étape.
5. Le reasoning doit justifier l'architecture choisie — notamment le choix des types de steps quand ce n'est pas évident.

## Types de steps disponibles

⚠️ **ATTENTION — CHAMPS OBLIGATOIRES** : chaque type a des champs que tu DOIS
remplir. Si tu omets un champ requis, le workflow sera rejeté à la persistance
avec une erreur explicite. Revérifie chaque step avant de finaliser.

### 1. `agent` (défaut)
Appelle un agent LLM nommé pour produire un output. C'est le type le plus courant.
**Champs OBLIGATOIRES** : `name`, `agent_name`.
**Champs optionnels** : `input_mapping`.

### 2. `conditional`
Évalue une expression JSONPath et produit un flag booléen `{matched: bool, value: any}`. N'appelle aucun LLM — coût tokens nul.
Utile pour exposer une décision réutilisable par les steps suivants (qui peuvent lire `$.steps.<name>.output.data.matched`).
**Champs OBLIGATOIRES** : `name`, `type: "conditional"`, **`condition`** (expression JSONPath non vide — commence par `$.`).
**Champs optionnels** : `equals` (pour comparaison stricte, sinon truthy check).

❌ **ERREUR FRÉQUENTE** : oublier le champ `condition`. Un `conditional` sans
   `condition` est invalide. Si tu définis un step conditional, tu DOIS
   préciser quelle expression évaluer.

### 3. `parallel`
Exécute plusieurs branches indépendantes qui partagent le même state initial mais ne peuvent pas se voir entre elles. Les outputs sont exposés sous `$.steps.<name>.output.data.branches.<branchName>`.
Utilise parallel quand tu as N traitements qui ne dépendent pas l'un de l'autre — ex: « résume en français ET en anglais en même temps ».
**Champs OBLIGATOIRES** : `name`, `type: "parallel"`, **`branches`** (array **non vide** d'au moins 2 steps complets, chacun avec son propre `name` + `type` + champs requis).

❌ **ERREUR FRÉQUENTE** : oublier le champ `branches`, ou le laisser vide. Un
   parallel sans branches est invalide. Chaque branche est un step COMPLET
   (mini-step imbriqué, pas juste une string).

### 4. `loop`
Itère un step template sur un array résolu par JSONPath. L'élément courant est exposé sous `$.inputs.item` (ou l'alias configuré), l'index sous `$.inputs.index`.
Utile quand tu dois appliquer le même traitement à N éléments d'une liste.
**Champs OBLIGATOIRES** : `name`, `type: "loop"`, **`items_path`** (expression JSONPath vers un array, ex: `$.inputs.documents`), **`step`** (un step complet à itérer, avec son propre type + champs requis).
**Champs optionnels** : `item_alias` (défaut `"item"`), `max_iterations` (défaut `50`).

### 5. `sub_workflow`
Délègue à un workflow persistant existant (par sa `key`). Permet la composition : un « workflow d'ingestion » peut réutiliser un « workflow de classification ».
**Champs OBLIGATOIRES** : `name`, `type: "sub_workflow"`, **`workflow_key`** (clé d'un workflow actif existant).
**Champs optionnels** : `input_mapping`.

## Exemple combiné

```json
{
  "key": "triage_et_resume",
  "name": "Triage et résumé d'emails",
  "description": "Classe un email par priorité, puis traite les urgents et non-urgents différemment.",
  "steps": [
    {
      "name": "classify",
      "agent_name": "classifier_priorite",
      "input_mapping": { "message": "$.inputs.email_body" }
    },
    {
      "name": "is_urgent",
      "type": "conditional",
      "condition": "$.steps.classify.output.data.priority",
      "equals": "urgent"
    },
    {
      "name": "double_summary",
      "type": "parallel",
      "branches": [
        { "name": "fr", "agent_name": "summarizer_fr", "input_mapping": { "text": "$.inputs.email_body" } },
        { "name": "en", "agent_name": "summarizer_en", "input_mapping": { "text": "$.inputs.email_body" } }
      ]
    }
  ],
  "reasoning": "..."
}
```

## Règles de bon goût

- Préfère un seul step `agent` bien pensé à une séquence de 5 steps qui font la même chose. La complexité n'est pas un but.
- Utilise `conditional` seulement si l'information doit être réutilisée par un step suivant. Pour une décision interne à un seul step, fais-la gérer par l'agent lui-même.
- Utilise `parallel` seulement si les branches sont vraiment indépendantes (aucune ne lit l'output d'une autre).
- Utilise `loop` pour des items homogènes (même traitement). Pour des items hétérogènes, utilise des steps séparés.
- Utilise `sub_workflow` seulement si le workflow cible est **déjà actif et promu** — tu ne peux pas référencer un workflow éphémère.

Réponds uniquement en JSON conforme au schéma fourni.
PROMPT;
    }

    private function formatAgentContext(SynapseAgent $agent): string
    {
        $lines = [
            sprintf('- **Clé** : %s', $agent->getKey()),
            sprintf('- **Nom** : %s', $agent->getName()),
        ];
        $desc = $agent->getDescription();
        if ('' !== trim($desc)) {
            $lines[] = sprintf('- **Description** : %s', $desc);
        }

        return implode("\n", $lines);
    }
}
