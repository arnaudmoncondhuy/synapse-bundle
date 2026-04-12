# delete_agent

Supprime un agent Synapse (identifié par `agentKey`).

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\DeleteAgentTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `agentKey` | `string` | oui | Clé unique de l'agent à supprimer |
| `force` | `?bool` | non | Si `true`, bypass la vérification de dépendances workflows actifs |

## Comportement de protection

Par défaut, l'outil refuse la suppression si l'agent est **référencé par au moins un workflow actif** et retourne la liste des workflows bloquants. Passez `force=true` pour outrepasser cette vérification.

!!! danger "Agents builtin"
    Les agents builtin (`isBuiltin=true`) **ne peuvent jamais être supprimés**, même avec `force=true`. Ce sont des agents fournis par le bundle ou déclarés comme non-supprimables en base.

## Réponse (succès)

```json
{
  "status": "success",
  "agentKey": "support_client",
  "message": "Agent \"support_client\" deleted.",
  "forced": false,
  "orphanedWorkflows": [],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Réponse (bloqué par dépendances)

```json
{
  "status": "error",
  "agentKey": "support_client",
  "error": "Cannot delete agent \"support_client\": still referenced by 2 active workflow(s). Pass force=true to delete anyway.",
  "referencingWorkflows": ["analyze_and_summarize", "support_pipeline"],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Voir aussi

- [`list_agents`](list-agents.md) — lister les agents disponibles
- [`update_agent`](update-agent.md) — modifier un agent
- [`cleanup_sandbox`](sandbox.md#cleanup_sandbox) — supprimer toutes les entités sandbox en bloc
