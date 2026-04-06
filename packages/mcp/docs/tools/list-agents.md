# list_agents

Liste tous les agents Synapse disponibles.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\ListAgentsTool
```

## Paramètres

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `includeSandbox` | `?bool` | `false` | Si `true`, inclut également les agents sandbox temporaires |

## Réponse

```json
{
  "status": "success",
  "count": 3,
  "agents": [
    {
      "key": "support_client",
      "name": "Support Client",
      "description": "Agent spécialisé dans le support utilisateur",
      "modelPreset": "Preset par défaut",
      "model": "gemini-2.5-flash",
      "tone": "zen",
      "allowedTools": [],
      "isActive": true,
      "isBuiltin": false,
      "isPublic": true,
      "isSandbox": false
    }
  ],
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Champs de sortie

| Champ | Description |
|-------|-------------|
| `key` | Clé unique de l'agent (utilisée dans `ChatService::ask()`) |
| `name` | Nom lisible |
| `description` | Description courte |
| `modelPreset` | Nom du preset LLM assigné (ou `null` si preset actif global) |
| `model` | Identifiant du modèle (ex: `gemini-2.5-flash`) |
| `tone` | Clé du ton de réponse (ou `null`) |
| `allowedTools` | Liste des noms d'outils autorisés (tableau vide = aucun outil) |
| `isActive` | Agent actif et utilisable |
| `isBuiltin` | Fourni par le bundle (ne peut pas être supprimé depuis l'admin) |
| `isPublic` | Accessible à tous (pas de restriction de rôle) |
| `isSandbox` | Agent temporaire créé via MCP |

## Comportement sandbox

Par défaut (`includeSandbox=false`), l'outil appelle `findAllOrdered()` qui exclut les agents sandbox. Avec `includeSandbox=true`, il appelle `findAll()` qui les inclut.

!!! note "Pas de vérification de permission"
    `ListAgentsTool` n'appelle pas `canAccessAdmin()`. Il est considéré comme un outil de lecture publique depuis le contexte MCP.

## Voir aussi

- [`run_agent_test`](run-agent-test.md) — exécuter un agent
- [Agents via l'admin](../../../core/docs/guides/tones-presets.md) — créer des agents depuis l'interface
