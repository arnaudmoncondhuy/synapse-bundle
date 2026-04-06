# inspect_agent_debug

Inspecte un log de debug d'exécution d'agent. Affiche le system prompt, le pipeline de prompt, les tokens et la trace complète d'exécution.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\InspectAgentDebugTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `debugId` | `string` | oui | Identifiant du log debug (retourné par `run_agent_test`) |

## Réponse (succès)

```json
{
  "status": "success",
  "debugId": "01HXYZ...",
  "createdAt": "2026-04-06T10:00:00+00:00",
  "systemPrompt": "Tu es un assistant spécialisé dans...",
  "history": [
    {"role": "system", "content": "..."},
    {"role": "user", "content": "Bonjour"}
  ],
  "turns": [
    {
      "role": "assistant",
      "content": "Bonjour ! Je suis là pour...",
      "tool_calls": []
    }
  ],
  "usage": {
    "prompt_tokens": 350,
    "completion_tokens": 120,
    "total_tokens": 470
  },
  "safetyRatings": [],
  "presetConfig": {
    "model": "gemini-2.5-flash",
    "temperature": 1.0
  },
  "rawRequest": "available",
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

## Champs de sortie

| Champ | Description |
|-------|-------------|
| `systemPrompt` | System prompt extrait du premier message de rôle `system` dans `history` |
| `history` | Historique des messages au format OpenAI canonical |
| `turns` | Échanges du multi-tour (assistant + tool calls) |
| `usage` | Tokens utilisés (prompt, completion, total) |
| `safetyRatings` | Évaluations de sécurité du provider |
| `presetConfig` | Configuration du preset utilisé |
| `rawRequest` | `"available"` si le payload brut est stocké, `"not-available"` sinon |

## Voir aussi

- [`run_agent_test`](run-agent-test.md) — générer un `debugId`
- [SynapseDebugLog](../../../core/docs/reference/entities.md#synapsedebuglog) — entité de stockage
