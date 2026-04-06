# update_agent_system_prompt

Propose une nouvelle version de system prompt pour un agent Synapse. Par défaut, la proposition entre dans la file d'attente HITL (Human-in-the-loop, Guardrail #3) et nécessite une approbation humaine depuis l'admin.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\UpdateAgentSystemPromptTool
```

## Paramètres

| Paramètre | Type | Requis | Description |
|-----------|------|--------|-------------|
| `agentId` | `string` | oui | Clé unique de l'agent à modifier |
| `systemPrompt` | `string` | oui | Nouveau system prompt proposé |
| `reason` | `?string` | non | Justification de la modification (affichée dans l'admin) |
| `mode` | `string` | non | `"pending"` (défaut) ou `"live"` |

## Modes

### Mode `pending` (recommandé)

La proposition est enregistrée dans `SynapseAgentPromptVersion` avec le statut `pending`. Le prompt **n'est pas appliqué** sur l'agent en cours. Un administrateur doit valider explicitement depuis l'interface admin.

```json
{
  "status": "success",
  "agentId": "support_client",
  "agentName": "Support Client",
  "mode": "pending",
  "message": "Proposal queued for human review (pending). It will NOT affect the live agent until an admin approves it.",
  "versionId": 42,
  "reason": "Optimisation du ton pour les utilisateurs débutants",
  "newSystemPrompt": "Tu es un assistant...",
  "timestamp": "2026-04-06T10:00:00+00:00"
}
```

### Mode `live`

Le prompt est appliqué immédiatement sur l'agent et une version est enregistrée.

!!! warning "Mode live déconseillé pour les appelants automatisés"
    Le mode `live` est réservé aux scripts de maintenance autorisés. Les clients MCP autonomes doivent utiliser le mode `pending` pour respecter le Guardrail #3.

## Guardrail #3 — HITL

Toute modification de system prompt via MCP passe par `PromptVersionRecorder::snapshot()`. Le mécanisme garantit :

1. **Traçabilité** : chaque version est horodatée avec la source (`mcp:claude`) et la raison
2. **Approbation humaine** : en mode `pending`, aucun impact sur la production avant validation
3. **Historique complet** : les versions précédentes sont conservées et consultables depuis l'admin

## Voir aussi

- [Guardrails & Sécurité](../../../core/docs/explanation/security.md)
- [AgentInterface](../../../core/docs/reference/contracts/agent-interface.md)
