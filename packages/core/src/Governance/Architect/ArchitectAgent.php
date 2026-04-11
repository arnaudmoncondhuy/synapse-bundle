<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\Architect;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
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
        private readonly AgentRegistry $agentRegistry,
        private readonly ToolRegistry $toolRegistry,
        private readonly MemoryManager $memoryManager,
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

        // Phase 1 pivot agentique : rassembler le contexte (mémoires utilisateur,
        // agents disponibles, tools disponibles) en amont du prompt. Sans ça,
        // l'architect hallucine des agent_name qui n'existent pas et ignore les
        // sources de données que l'utilisateur utilise vraiment.
        $contextUserId = $this->extractUserId($options, $structured);
        $architectContext = $this->gatherContext((string) $description, $contextUserId);

        $prompt = $this->buildPrompt($action, (string) $description, $structured, $architectContext);
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
     * @param array{memories: list<string>, agents: list<array{key: string, name: string, description: string}>, tools: list<array{name: string, description: string}>} $context
     */
    private function buildPrompt(string $action, string $description, array $structured, array $context): ?string
    {
        return match ($action) {
            'create_agent' => $this->buildCreateAgentPrompt($description, $context),
            'improve_prompt' => $this->buildImprovePromptPrompt($description, $structured, $context),
            'create_workflow' => $this->buildCreateWorkflowPrompt($description, $context),
            default => null,
        };
    }

    /**
     * Extrait le user_id depuis les options (AgentContext) ou le structured input.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $structured
     */
    private function extractUserId(array $options, array $structured): ?string
    {
        $ctx = $options['context'] ?? null;
        if ($ctx instanceof AgentContext) {
            $uid = $ctx->getUserId();
            if (null !== $uid && '' !== $uid) {
                return $uid;
            }
        }

        $fromStructured = $structured['user_id'] ?? null;
        if (is_string($fromStructured) && '' !== $fromStructured) {
            return $fromStructured;
        }

        return null;
    }

    /**
     * Rassemble le contexte injecté dans le prompt de l'architect :
     * - les mémoires utilisateur recalled (via recherche sémantique sur la description)
     * - la liste des agents actifs visibles (depuis AgentRegistry, déjà filtrée par permissions)
     * - la liste des tools disponibles (depuis ToolRegistry)
     *
     * Chaque section est optionnelle — si une source est vide, la section correspondante
     * est simplement omise du prompt. Les erreurs sont avalées par défaut pour ne pas
     * bloquer la génération si par exemple l'embedding service est temporairement down.
     *
     * @return array{memories: list<string>, agents: list<array{key: string, name: string, description: string}>, tools: list<array{name: string, description: string}>}
     */
    private function gatherContext(string $query, ?string $userId): array
    {
        $memories = [];
        if (null !== $userId && '' !== $userId && '' !== trim($query)) {
            try {
                $recalled = $this->memoryManager->recall($query, $userId, null, 10);
                foreach ($recalled as $entry) {
                    $content = trim((string) ($entry['content'] ?? ''));
                    if ('' !== $content) {
                        $memories[] = $content;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ArchitectAgent: memory recall failed — {message}', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $agents = [];
        try {
            foreach ($this->agentRegistry->getAll() as $key => $data) {
                $agents[] = [
                    'key' => is_string($key) ? $key : '',
                    'name' => is_string($data['name'] ?? null) ? (string) $data['name'] : '',
                    'description' => is_string($data['description'] ?? null) ? (string) $data['description'] : '',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ArchitectAgent: agent registry listing failed — {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        $tools = [];
        try {
            foreach ($this->toolRegistry->getDefinitions() as $def) {
                $name = is_string($def['name'] ?? null) ? (string) $def['name'] : '';
                if ('' === $name) {
                    continue;
                }
                $tools[] = [
                    'name' => $name,
                    'description' => is_string($def['description'] ?? null) ? (string) $def['description'] : '',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ArchitectAgent: tool registry listing failed — {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'memories' => $memories,
            'agents' => $agents,
            'tools' => $tools,
        ];
    }

    /**
     * Formate les 3 sections de contexte (mémoires/agents/tools) en markdown
     * pour injection dans le prompt. Retourne une chaîne vide si TOUTES les
     * sections sont vides, sinon une chaîne préfixée d'un saut de ligne.
     *
     * @param array{memories: list<string>, agents: list<array{key: string, name: string, description: string}>, tools: list<array{name: string, description: string}>} $context
     */
    private function formatContextSections(array $context): string
    {
        $parts = [];

        if ([] !== $context['agents']) {
            $lines = ['## Agents disponibles', '', 'Voici les agents déjà configurés dans Synapse. Tu **DOIS** utiliser ces `agent_name` dans les steps de type `agent` — ne pas en inventer d\'autres. Si aucun agent existant ne convient à une étape, propose un nouveau step `agent` mais choisis un `agent_name` descriptif que l\'utilisateur pourra créer ensuite (et mentionne-le dans ton `reasoning`).', ''];
            foreach ($context['agents'] as $agent) {
                $desc = '' !== $agent['description'] ? ' — '.$agent['description'] : '';
                $lines[] = sprintf('- `%s` (%s)%s', $agent['key'], $agent['name'], $desc);
            }
            $parts[] = implode("\n", $lines);
        }

        if ([] !== $context['tools']) {
            $lines = ['## Outils disponibles', '', 'Ces outils peuvent être référencés dans `allowed_tools` (pour un agent) ou invoqués par les agents pendant leur exécution. Choisis-les uniquement si la mission en a vraiment besoin.', ''];
            foreach ($context['tools'] as $tool) {
                $desc = '' !== $tool['description'] ? ' — '.$tool['description'] : '';
                $lines[] = sprintf('- `%s`%s', $tool['name'], $desc);
            }
            $parts[] = implode("\n", $lines);
        }

        if ([] !== $context['memories']) {
            $lines = ['## Contexte utilisateur (mémoires pertinentes)', '', 'Ces informations viennent de la mémoire de l\'utilisateur connecté. Utilise-les pour personnaliser ta proposition — par exemple, si l\'utilisateur a dit qu\'il utilise Google Calendar pour ses rendez-vous, et que la tâche implique de consulter un calendrier, privilégie un agent qui sait lire Google Calendar plutôt qu\'un agent générique.', ''];
            foreach ($context['memories'] as $memory) {
                $lines[] = '- '.$memory;
            }
            $parts[] = implode("\n", $lines);
        }

        if ([] === $parts) {
            return '';
        }

        return "\n\n".implode("\n\n", $parts);
    }

    /**
     * @param array{memories: list<string>, agents: list<array{key: string, name: string, description: string}>, tools: list<array{name: string, description: string}>} $context
     */
    private function buildCreateAgentPrompt(string $description, array $context): string
    {
        $contextSections = $this->formatContextSections($context);

        return <<<PROMPT
Tu es un expert en ingénierie de prompts pour des agents LLM.

## Ta mission

Concevoir un agent IA complet à partir de la description suivante.

## Description demandée

{$description}{$contextSections}

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
     * @param array{memories: list<string>, agents: list<array{key: string, name: string, description: string}>, tools: list<array{name: string, description: string}>} $context
     */
    private function buildImprovePromptPrompt(string $description, array $structured, array $context): ?string
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
        $contextSections = $this->formatContextSections($context);

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

{$description}{$instructions}{$contextSections}

## Règles

1. Retourne le prompt **complet** (remplacement intégral, pas un diff).
2. Conserve les éléments fonctionnels du prompt existant sauf si la demande dit explicitement de les retirer.
3. Améliore la clarté, la spécificité, la sécurité et la cohérence.
4. Le résumé des changements doit être concis (2-5 phrases) et factuel.
5. Le reasoning doit justifier chaque modification.

Réponds uniquement en JSON conforme au schéma fourni.
PROMPT;
    }

    /**
     * @param array{memories: list<string>, agents: list<array{key: string, name: string, description: string}>, tools: list<array{name: string, description: string}>} $context
     */
    private function buildCreateWorkflowPrompt(string $description, array $context): string
    {
        $contextSections = $this->formatContextSections($context);

        return <<<PROMPT
Tu es un expert en orchestration de workflows multi-agents.

## Ta mission

Concevoir un workflow à partir de la description suivante.

## Description demandée

{$description}{$contextSections}

## Format d'un step

**CHAQUE step a exactement 3 champs** : `name`, `type`, et `config`.
Les paramètres spécifiques au type vont **TOUS dans `config`** — JAMAIS directement sur le step.

```json
{ "name": "mon_step", "type": "agent", "config": { "agent_name": "redacteur" } }
```

## Les 5 types de steps

### `agent` — appeler un agent LLM
```json
{ "name": "classify", "type": "agent", "config": {
    "agent_name": "email_classifier",
    "input_mapping": { "message": "$.inputs.body" }
}}
```
Champs config : `agent_name` (obligatoire), `input_mapping` (optionnel).

### `conditional` — évaluer une expression, produire un flag
```json
{ "name": "is_urgent", "type": "conditional", "config": {
    "condition": "$.steps.classify.output.data.priority",
    "equals": "urgent"
}}
```
Champs config : `condition` (obligatoire, expression JSONPath), `equals` (optionnel — si omis, truthy check).
Accessible ensuite via `$.steps.is_urgent.output.data.matched` (true/false).

### `parallel` — N branches indépendantes
```json
{ "name": "fanout", "type": "parallel", "config": {
    "branches": [
        { "name": "summarize", "type": "agent", "config": { "agent_name": "summarizer" } },
        { "name": "extract", "type": "agent", "config": { "agent_name": "extractor" } }
    ]
}}
```
Champ config : `branches` (obligatoire, array d'au moins 2 steps COMPLETS avec leur propre `name`/`type`/`config`).
Outputs accessibles via `$.steps.fanout.output.data.branches.summarize.text` etc.

### `loop` — itérer un template sur un array
```json
{ "name": "per_doc", "type": "loop", "config": {
    "items_path": "$.inputs.documents",
    "step": { "name": "process", "type": "agent", "config": { "agent_name": "processor" } },
    "item_alias": "doc",
    "max_iterations": 50
}}
```
Champs config : `items_path` (obligatoire, JSONPath vers un array), `step` (obligatoire, step complet avec son `config`), `item_alias` (optionnel, défaut "item"), `max_iterations` (optionnel, défaut 50).

### `sub_workflow` — déléguer à un workflow persistant
```json
{ "name": "delegate", "type": "sub_workflow", "config": {
    "workflow_key": "email_pipeline",
    "input_mapping": { "email": "$.inputs.raw" }
}}
```
Champs config : `workflow_key` (obligatoire), `input_mapping` (optionnel).

## Exemple complet

```json
{
    "key": "triage_email_urgent",
    "name": "Triage emails urgents",
    "description": "Classe la priorité, puis pour les urgents résume + extrait en parallèle.",
    "steps": [
        { "name": "classify", "type": "agent", "config": {
            "agent_name": "email_classifier",
            "input_mapping": { "message": "$.inputs.email_body" }
        }},
        { "name": "is_urgent", "type": "conditional", "config": {
            "condition": "$.steps.classify.output.data.priority",
            "equals": "urgent"
        }},
        { "name": "process", "type": "parallel", "config": {
            "branches": [
                { "name": "summarize", "type": "agent", "config": { "agent_name": "email_summarizer" } },
                { "name": "extract_sender", "type": "agent", "config": { "agent_name": "sender_extractor" } }
            ]
        }}
    ],
    "reasoning": "..."
}
```

## Règles

- Préfère un seul step `agent` bien pensé à plusieurs steps qui font la même chose.
- Utilise `conditional` seulement si son résultat est consommé par un step suivant.
- Utilise `parallel` seulement si les branches sont vraiment indépendantes.
- Utilise `loop` pour des items homogènes (même traitement sur chaque).
- Utilise `sub_workflow` seulement pour un workflow déjà actif et promu.

Réponds uniquement en JSON conforme au schéma fourni. **N'oublie JAMAIS le champ `config` — il est OBLIGATOIRE sur tous les steps**.
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
