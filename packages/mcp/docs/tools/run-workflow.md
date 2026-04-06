# run_workflow

Exécute un workflow Synapse via `WorkflowRunner`. Déclenche le pipeline `MultiAgent` qui résout chaque agent via `AgentResolver`, permettant une traçabilité complète.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\RunWorkflowTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `workflowKey` | `string` | oui | Clé unique du workflow à exécuter |
| `inputs` | `?string` | non | Données structurées en JSON (pour les étapes à mapping d'entrées) |
| `message` | `?string` | non | Message textuel simple (si `inputs` absent ou invalide) |

### Ordre de priorité des inputs

1. Si `inputs` est une chaîne JSON valide → `Input::ofStructured($parsed)`
2. Sinon → `Input::ofMessage($message ?? '')`

## Réponse (succès)

```json
{
  "status": "success",
  "workflowKey": "analyse_complete",
  "workflowRunId": "run-01HXYZ...",
  "stepsExecuted": 3,
  "outputs": {
    "summary": "Le document présente...",
    "risk_level": "medium"
  },
  "answer": "Le document présente...",
  "usage": {
    "total_tokens": 1450
  },
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Réponse (erreur)

```json
{
  "status": "error",
  "workflowKey": "analyse_complete",
  "error": "WorkflowExecutionException: Agent 'analyseur' failed at step 'analyse': ...",
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Contexte d'exécution

L'outil crée un contexte racine avec `origin='mcp'` via `AgentResolver::createRootContext()`. Ce contexte propage la traçabilité (profondeur, workflowRunId) à tous les sous-agents.

## Voir aussi

- [`inspect_workflow_run`](inspect-workflow-run.md) — inspecter le run en détail
- [`create_sandbox_workflow`](sandbox.md#create_sandbox_workflow) — créer un workflow temporaire
- [Architecture multi-agents](../../../core/docs/explanation/architecture.md) — flux d'exécution
