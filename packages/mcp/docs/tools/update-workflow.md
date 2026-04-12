# update_workflow

Met à jour un workflow Synapse existant (identifié par `workflowKey`).

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\UpdateWorkflowTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `workflowKey` | `string` | oui | Clé unique du workflow à modifier |
| `name` | `?string` | non | Nouveau nom d'affichage |
| `description` | `?string` | non | Nouvelle description |
| `definition` | `?string` | non | Définition JSON complète des étapes (format pivot). Doit contenir un tableau `steps` non vide |
| `isActive` | `?bool` | non | Activer ou désactiver le workflow |
| `sortOrder` | `?int` | non | Ordre d'affichage dans l'admin |

Tout paramètre laissé à `null` est laissé inchangé. La version du workflow est **incrémentée automatiquement** à chaque modification de la `definition`.

## Format de la définition

```json
{
  "version": 1,
  "steps": [
    {
      "name": "step1",
      "agent_name": "document_analyzer",
      "input_mapping": {},
      "output_key": "analysis"
    },
    {
      "name": "step2",
      "agent_name": "summarizer",
      "input_mapping": {
        "analysis": "$.steps.step1.output.answer"
      },
      "output_key": "summary"
    }
  ],
  "outputs": {
    "final": "$.steps.step2.output.answer"
  }
}
```

## Réponse (succès)

```json
{
  "status": "success",
  "workflowKey": "analyze_and_summarize",
  "changed": ["definition", "isActive"],
  "workflow": {
    "workflowKey": "analyze_and_summarize",
    "name": "Analyser et Résumer",
    "version": 4,
    "isActive": true,
    "stepsCount": 2
  },
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Voir aussi

- [`list_workflows`](list-workflows.md) — lister les workflows disponibles
- [`run_workflow`](run-workflow.md) — exécuter un workflow
- [`create_sandbox_workflow`](sandbox.md#create_sandbox_workflow) — créer un workflow temporaire
- [`delete_workflow`](delete-workflow.md) — supprimer un workflow
