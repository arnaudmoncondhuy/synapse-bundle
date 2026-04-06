# inspect_workflow_run

Inspecte un run de workflow complété ou échoué. Affiche le statut, la progression par étape, les entrées/sorties, les tokens, la durée et les détails d'erreur.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\InspectWorkflowRunTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `workflowRunId` | `string` | oui | UUID du run (retourné par `run_workflow`) |

## Réponse (succès)

```json
{
  "status": "success",
  "workflowRunId": "run-01HXYZ...",
  "workflowKey": "analyse_complete",
  "workflowVersion": 1,
  "runStatus": "completed",
  "currentStepIndex": 3,
  "stepsCount": 3,
  "input": {"message": "Analyse ce document..."},
  "output": {"summary": "...", "risk_level": "medium"},
  "errorMessage": null,
  "totalTokens": 1450,
  "durationSeconds": 4.23,
  "startedAt": "2026-04-06T10:00:00+00:00",
  "completedAt": "2026-04-06T10:00:04+00:00",
  "timestamp": "2026-04-06T10:00:05+00:00"
}
```

## Champs de sortie

| Champ | Description |
|-------|-------------|
| `runStatus` | Statut du run : `running`, `completed`, `failed` |
| `currentStepIndex` | Index de la dernière étape exécutée (base 0) |
| `stepsCount` | Nombre total d'étapes du workflow |
| `input` | Données d'entrée passées au workflow |
| `output` | Sorties produites (format dépend du workflow) |
| `errorMessage` | Message d'erreur si `runStatus = failed` |
| `totalTokens` | Tokens totaux consommés par toutes les étapes |
| `durationSeconds` | Durée d'exécution en secondes |

## Voir aussi

- [`run_workflow`](run-workflow.md) — obtenir un `workflowRunId`
- [SynapseWorkflowRun](../../../core/docs/reference/entities.md) — entité de stockage
