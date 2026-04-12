# delete_workflow

Supprime un workflow Synapse (identifié par `workflowKey`).

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\DeleteWorkflowTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `workflowKey` | `string` | oui | Clé unique du workflow à supprimer |
| `force` | `?bool` | non | Si `true`, bypass la vérification des agents façades actifs |

## Comportement de protection

Par défaut, l'outil refuse la suppression si le workflow est utilisé comme **façade par au moins un agent actif** (champ `workflowKey` sur `SynapseAgent`). Passez `force=true` pour outrepasser cette vérification.

!!! danger "Workflows builtin"
    Les workflows builtin (`isBuiltin=true`) **ne peuvent jamais être supprimés**, même avec `force=true`.

!!! note "Historique des runs"
    La suppression du workflow ne supprime pas les `SynapseWorkflowRun` existants. Les runs gardent le `workflowKey` dénormalisé et restent consultables via [`inspect_workflow_run`](inspect-workflow-run.md).

## Réponse (succès)

```json
{
  "status": "success",
  "workflowKey": "analyze_and_summarize",
  "message": "Workflow \"analyze_and_summarize\" deleted.",
  "forced": false,
  "orphanedAgents": [],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Réponse (bloqué par dépendances)

```json
{
  "status": "error",
  "workflowKey": "analyze_and_summarize",
  "error": "Cannot delete workflow \"analyze_and_summarize\": still referenced by 1 active agent(s). Pass force=true to delete anyway.",
  "referencingAgents": ["document_processor"],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Voir aussi

- [`list_workflows`](list-workflows.md) — lister les workflows disponibles
- [`update_workflow`](update-workflow.md) — modifier un workflow
- [`cleanup_sandbox`](sandbox.md#cleanup_sandbox) — supprimer toutes les entités sandbox en bloc
