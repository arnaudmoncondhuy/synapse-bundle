# run_agent_test

Exécute un agent Synapse et retourne la réponse avec les métriques d'utilisation.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\RunAgentTestTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `agentKey` | `string` | oui | Clé unique de l'agent à exécuter |
| `input` | `string` | oui | Message envoyé à l'agent |
| `userId` | `?int` | non | Identifiant utilisateur pour le token accounting |

## Réponse (succès)

```json
{
  "status": "success",
  "agentKey": "support_client",
  "agentName": "Support Client",
  "input": "Bonjour, j'ai un problème avec mon compte",
  "answer": "Bonjour ! Je suis là pour vous aider...",
  "model": "gemini-2.5-flash",
  "debugId": "01HXYZ...",
  "usage": {
    "prompt_tokens": 350,
    "completion_tokens": 120,
    "total_tokens": 470
  },
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Réponse (erreur)

```json
{
  "status": "error",
  "agentKey": "agent_inexistant",
  "error": "Agent not found: 'agent_inexistant'. Use list_agents to get available keys.",
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Détails d'implémentation

L'outil appelle directement `ChatService::ask()` avec les options `debug=true` et `streaming=false`. Le `debugId` retourné peut être inspecté via [`inspect_agent_debug`](inspect-agent-debug.md).

!!! note "Agents sandbox"
    Cet outil utilise `AgentRegistry` (agents "config" en BDD via `findByKey()`), qui inclut les agents sandbox. Il est donc possible de tester un agent créé avec `create_sandbox_agent` via cet outil.

## Voir aussi

- [`list_agents`](list-agents.md) — lister les agents disponibles
- [`inspect_agent_debug`](inspect-agent-debug.md) — inspecter le log complet
- [`create_sandbox_agent`](sandbox.md#create_sandbox_agent) — créer un agent temporaire
