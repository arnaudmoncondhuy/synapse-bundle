# MCP Sandbox — Tests autonomes multi-agents

Le pattern **Sandbox** (Phase 10) permet à un client MCP de créer une configuration complète de test (preset + agent + workflow), d'exécuter des scenarios, d'inspecter les résultats, puis de tout nettoyer en une seule opération.

Les entités sandbox sont des entités Doctrine normales marquées `isSandbox = true`. Elles sont **invisibles dans l'admin et le chat** mais **résolvables pour l'exécution**.

## Cycle de vie recommandé

```
1. list_presets              → identifier les providers/modèles disponibles
2. create_sandbox_preset     → créer un preset temporaire
3. list_agents               → vérifier les agents existants
4. create_sandbox_agent      → créer un agent temporaire avec system prompt
5. create_sandbox_workflow   → assembler le pipeline multi-agents
6. run_workflow               → exécuter
7. inspect_workflow_run      → analyser les résultats
8. cleanup_sandbox            → supprimer toutes les entités sandbox
```

---

## create_sandbox_preset

Crée un preset de modèle temporaire.

**Namespace** : `ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxPresetTool`

### Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `key` | `string` | oui | Clé unique (`[a-z0-9_-]`, max 50 chars) |
| `name` | `string` | oui | Nom lisible |
| `providerName` | `string` | oui | Nom du provider (ex: `gemini`) |
| `model` | `string` | oui | Identifiant du modèle, validé via `ModelCapabilityRegistry` |
| `temperature` | `?float` | non | Température (défaut : `1.0`) |
| `topP` | `?float` | non | Top-P (défaut : `0.95`) |
| `maxOutputTokens` | `?int` | non | Limite de tokens de sortie |
| `streamingEnabled` | `?bool` | non | Streaming SSE (défaut : `false`) |

### Réponse (succès)

```json
{
  "status": "success",
  "presetKey": "test_gemini_flash",
  "name": "Test Gemini Flash",
  "providerName": "gemini",
  "model": "gemini-2.5-flash",
  "temperature": 1.0,
  "streamingEnabled": false,
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

### Validations

- La clé doit correspondre à `[a-z0-9_-]+` et ne pas dépasser 50 caractères
- La clé ne doit pas déjà exister
- Le modèle doit être connu de `ModelCapabilityRegistry`
- Le preset sandbox est créé avec `isActive = false` (ne devient jamais le preset actif global)

!!! tip "Trouver les modèles disponibles"
    Si le modèle n'est pas connu, le message d'erreur liste les modèles disponibles pour le provider indiqué.

---

## create_sandbox_agent

Crée un agent temporaire avec system prompt et preset assigné.

**Namespace** : `ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxAgentTool`

### Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `key` | `string` | oui | Clé unique (`[a-z0-9_-]`, max 50 chars) |
| `name` | `string` | oui | Nom lisible |
| `systemPrompt` | `string` | oui | Instructions du system prompt |
| `description` | `?string` | non | Description courte |
| `presetKey` | `?string` | non | Clé du preset à utiliser (si absent : preset actif global) |
| `allowedToolNames` | `?string` | non | Noms d'outils autorisés séparés par des virgules |

### Réponse (succès)

```json
{
  "status": "success",
  "agentKey": "test_analyseur",
  "agentName": "Analyseur Test",
  "presetUsed": "Test Gemini Flash",
  "model": "gemini-2.5-flash",
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

### Comportement

- L'agent est créé avec `isActive = true` et `isSandbox = true`
- `isBuiltin = false` (pas de protection contre la suppression)
- Si `presetKey` est absent, `findActive()` est appelé (sans filtre sandbox, donc le preset actif du système est utilisé)
- L'agent est immédiatement résolvable par `AgentResolver::resolve()` et `AgentResolver::has()`

---

## create_sandbox_workflow

Assemble un pipeline multi-agents temporaire.

**Namespace** : `ArnaudMoncondhuy\SynapseMcp\Tool\CreateSandboxWorkflowTool`

### Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `workflowKey` | `string` | oui | Clé unique (`[a-z0-9_-]`, max 100 chars) |
| `name` | `string` | oui | Nom lisible |
| `definition` | `string` | oui | Définition JSON du workflow (format pivot) |
| `description` | `?string` | non | Description du workflow |

### Format de la définition (format pivot)

```json
{
  "version": 1,
  "steps": [
    {
      "name": "etape1",
      "agent_name": "test_analyseur",
      "input_mapping": {},
      "output_key": "analyse"
    },
    {
      "name": "etape2",
      "agent_name": "test_synthesiseur",
      "input_mapping": {
        "text": "$.steps.etape1.output.answer"
      },
      "output_key": "synthese"
    }
  ],
  "outputs": {
    "resultat_final": "$.steps.etape2.output.answer"
  }
}
```

### Réponse (succès)

```json
{
  "status": "success",
  "workflowKey": "test_pipeline",
  "name": "Pipeline de Test",
  "stepsCount": 2,
  "agents": ["test_analyseur", "test_synthesiseur"],
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

### Validations

- Le JSON doit être valide
- Le tableau `steps` doit être non-vide
- Chaque étape doit avoir `name` et `agent_name` non vides
- Les noms d'étapes doivent être uniques dans la définition
- Chaque `agent_name` doit être résolvable via `AgentResolver::has()` (agents code + agents BDD, y compris sandbox)

!!! warning "Créer les agents avant le workflow"
    Les agents référencés dans `agent_name` doivent exister avant d'appeler `create_sandbox_workflow`. L'outil vérifie leur existence au moment de la création.

---

## cleanup_sandbox

Supprime toutes les entités sandbox dans l'ordre correct pour respecter les contraintes de clé étrangère.

**Namespace** : `ArnaudMoncondhuy\SynapseMcp\Tool\CleanupSandboxTool`

### Paramètres

Aucun.

### Ordre de suppression

1. Runs de workflow (via `deleteByWorkflowKeys()` — supprime les `SynapseWorkflowRun` des workflows sandbox)
2. Workflows sandbox
3. Agents sandbox (avant les presets car ils y font référence via `ManyToOne`)
4. Presets sandbox

### Réponse

```json
{
  "status": "success",
  "workflowRunsDeleted": 5,
  "workflowsDeleted": 1,
  "agentsDeleted": 2,
  "presetsDeleted": 1,
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

!!! note "Idempotent"
    `cleanup_sandbox` peut être appelé plusieurs fois sans erreur. Si aucune entité sandbox n'existe, tous les compteurs sont à 0.

!!! info "Logs de debug conservés"
    Les `SynapseDebugLog` générés pendant les tests sandbox ne sont **pas supprimés**. Ils restent consultables via `inspect_agent_debug` ou l'admin debug.

---

## Pattern sandbox complet — Exemple

```
# 1. Lister les presets disponibles
list_presets → {"presets": [{"key": "main", "providerName": "gemini", "model": "gemini-2.5-flash"}]}

# 2. Créer un preset sandbox
create_sandbox_preset key="sb_flash" name="Sandbox Flash" providerName="gemini" model="gemini-2.5-flash"

# 3. Créer deux agents sandbox
create_sandbox_agent key="sb_lecteur" name="Lecteur" systemPrompt="Tu lis et résumes des textes." presetKey="sb_flash"
create_sandbox_agent key="sb_critique" name="Critique" systemPrompt="Tu évalues la qualité d'un résumé." presetKey="sb_flash"

# 4. Créer le workflow
create_sandbox_workflow workflowKey="sb_pipeline" name="Pipeline Test" definition='{"version":1,"steps":[{"name":"lecture","agent_name":"sb_lecteur","input_mapping":{},"output_key":"resume"},{"name":"critique","agent_name":"sb_critique","input_mapping":{"text":"$.steps.lecture.output.answer"},"output_key":"evaluation"}],"outputs":{"final":"$.steps.critique.output.answer"}}'

# 5. Exécuter
run_workflow workflowKey="sb_pipeline" message="Article à analyser : Lorem ipsum..."
→ {"workflowRunId": "run-01HXYZ...", "outputs": {"final": "Le résumé est..."}}

# 6. Inspecter si nécessaire
inspect_workflow_run workflowRunId="run-01HXYZ..."

# 7. Nettoyer
cleanup_sandbox → {"workflowRunsDeleted": 1, "workflowsDeleted": 1, "agentsDeleted": 2, "presetsDeleted": 1}
```

## Voir aussi

- [Architecture multi-agents](../../../core/docs/explanation/architecture.md)
- [Pattern sandbox sur les entités](../../../core/docs/reference/entities.md#pattern-sandbox)
- [`run_workflow`](run-workflow.md)
- [`inspect_workflow_run`](inspect-workflow-run.md)
