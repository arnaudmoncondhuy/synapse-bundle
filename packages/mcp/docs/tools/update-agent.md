# update_agent

Met à jour les métadonnées éditables d'un agent Synapse existant (identifié par `agentKey`).

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\UpdateAgentTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `agentKey` | `string` | oui | Clé unique de l'agent à modifier |
| `name` | `?string` | non | Nouveau nom lisible |
| `emoji` | `?string` | non | Emoji d'affichage |
| `description` | `?string` | non | Nouvelle description |
| `modelPresetKey` | `?string` | non | Clé du preset LLM à assigner (doit exister via `list_presets`) |
| `allowedToolNames` | `?string` | non | Outils autorisés, séparés par des virgules. Chaîne vide `""` = aucune restriction |
| `isActive` | `?bool` | non | Activer ou désactiver l'agent |

Tout paramètre laissé à `null` est laissé inchangé.

!!! warning "Modifier le system prompt"
    Pour modifier le system prompt d'un agent, utiliser [`update_agent_system_prompt`](update-agent-system-prompt.md) qui implémente le workflow HITL (validation humaine avant activation).

## Réponse (succès)

```json
{
  "status": "success",
  "agentKey": "support_client",
  "changed": ["name", "allowedToolNames", "isActive"],
  "agent": {
    "key": "support_client",
    "name": "Support Client Premium",
    "emoji": "🎯",
    "description": "Agent spécialisé dans le support utilisateur",
    "modelPresetKey": "gemini_flash",
    "allowedToolNames": ["web_search"],
    "isActive": true,
    "isBuiltin": false
  },
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Réponse (aucun changement)

```json
{
  "status": "success",
  "agentKey": "support_client",
  "message": "No fields provided — nothing changed.",
  "changed": [],
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Voir aussi

- [`list_agents`](list-agents.md) — lister les agents disponibles
- [`update_agent_system_prompt`](update-agent-system-prompt.md) — modifier le system prompt (HITL)
- [`delete_agent`](delete-agent.md) — supprimer un agent
