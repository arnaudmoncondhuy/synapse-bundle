# list_workflows

Liste tous les workflows Synapse disponibles avec leurs métadonnées.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\ListWorkflowsTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `includeSandbox` | `?bool` | `false` | Si `true`, inclut également les workflows sandbox temporaires |

## Réponse

```json
{
  "status": "success",
  "count": 2,
  "workflows": [
    {
      "workflowKey": "analyze_and_summarize",
      "name": "Analyser et Résumer",
      "description": "Analyse un document puis en produit un résumé structuré.",
      "version": 3,
      "isActive": true,
      "isBuiltin": false,
      "isSandbox": false,
      "sortOrder": 10,
      "stepsCount": 2
    }
  ],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Champs de sortie

| Champ | Description |
|-------|-------------|
| `workflowKey` | Clé unique du workflow (utilisée dans `run_workflow`) |
| `name` | Nom d'affichage |
| `description` | Description courte |
| `version` | Version courante de la définition (incrémentée à chaque modification) |
| `isActive` | Workflow actif et exécutable |
| `isBuiltin` | Fourni par le bundle (ne peut pas être supprimé depuis l'admin) |
| `isSandbox` | Workflow temporaire créé via MCP |
| `sortOrder` | Ordre d'affichage dans l'admin |
| `stepsCount` | Nombre d'étapes dans la définition courante |

## Voir aussi

- [`run_workflow`](run-workflow.md) — exécuter un workflow
- [`inspect_workflow_run`](inspect-workflow-run.md) — inspecter un run
- [`create_sandbox_workflow`](sandbox.md#create_sandbox_workflow) — créer un workflow temporaire
