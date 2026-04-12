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
  "source": "db",
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

Le champ `source` indique l'origine de l'agent : `"db"` (agent BDD) ou `"code"` (agent code PHP).

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

L'outil résout l'agent dans cet ordre : BDD d'abord (`AgentRegistry`), puis agents code (`CodeAgentRegistry`). Il appelle ensuite `ChatService::ask()` avec les options `streaming=false`. Le `debugId` retourné peut être inspecté via [`inspect_agent_debug`](inspect-agent-debug.md).

!!! note "Agents sandbox et agents code"
    Cet outil résout les deux types d'agents : agents "config" en BDD (y compris sandbox, via `findByKey()`) et agents "code" PHP (via `CodeAgentRegistry`). Il est possible de tester un agent créé avec `create_sandbox_agent` ou un agent code déclaré dans l'application hôte.

!!! warning "Configuration DI du package MCP"
    Le fichier `packages/mcp/config/services.yaml` doit déclarer `CodeAgentRegistry` comme argument de `RunAgentTestTool` pour que la résolution des agents code fonctionne. Vérifier que cette injection est présente dans votre installation.

## Voir aussi

- [`list_agents`](list-agents.md) — lister les agents disponibles
- [`inspect_agent_debug`](inspect-agent-debug.md) — inspecter le log complet
- [`create_sandbox_agent`](sandbox.md#create_sandbox_agent) — créer un agent temporaire
