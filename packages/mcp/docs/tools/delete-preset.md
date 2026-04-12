# delete_preset

Supprime un preset LLM Synapse (identifié par `key`).

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\DeletePresetTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `key` | `string` | oui | Clé unique du preset à supprimer |
| `force` | `?bool` | non | Si `true`, bypass la vérification de dépendances agents |

## Comportement de protection

Par défaut, l'outil refuse la suppression si le preset est **référencé explicitement par au moins un agent** et retourne la liste des agents bloquants. Passez `force=true` pour outrepasser cette vérification.

!!! warning "Agents orphelins"
    En cas de suppression forcée, les agents qui référençaient ce preset basculent automatiquement vers le preset actif global — ils ne seront pas supprimés mais pourraient avoir un comportement différent.

## Réponse (succès)

```json
{
  "status": "success",
  "key": "gemini_flash",
  "message": "Preset \"gemini_flash\" deleted.",
  "forced": false,
  "orphanedAgents": [],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Réponse (bloqué par dépendances)

```json
{
  "status": "error",
  "key": "gemini_flash",
  "error": "Cannot delete preset \"gemini_flash\": still referenced by 3 agent(s). Pass force=true to delete anyway.",
  "referencingAgents": ["support_client", "rh_assistant", "analyst"],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Voir aussi

- [`list_presets`](list-presets.md) — lister les presets disponibles
- [`update_preset`](update-preset.md) — modifier un preset
- [`cleanup_sandbox`](sandbox.md#cleanup_sandbox) — supprimer toutes les entités sandbox en bloc
