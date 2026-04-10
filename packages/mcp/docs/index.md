# Synapse MCP

Le package `synapse-mcp` expose une interface **Model Context Protocol (MCP)** qui permet à un client MCP (Claude Desktop, etc.) d'interagir directement avec Synapse : lister les agents et presets, créer des entités temporaires de test (sandbox), exécuter des workflows et inspecter les résultats.

## Vue d'ensemble des outils

### Outils de lecture

| Outil | Description |
|-------|-------------|
| [`list_agents`](tools/list-agents.md) | Liste les agents disponibles (clé, nom, preset, outils, statut) |
| [`list_presets`](tools/list-presets.md) | Liste les presets disponibles (provider, modèle, paramètres) |

### Outils d'exécution

| Outil | Description |
|-------|-------------|
| [`run_agent_test`](tools/run-agent-test.md) | Exécute un agent et retourne la réponse avec métriques |
| [`run_workflow`](tools/run-workflow.md) | Exécute un workflow complet via `WorkflowRunner` |
| [`inspect_agent_debug`](tools/inspect-agent-debug.md) | Inspecte un log de debug par `debugId` |
| [`inspect_workflow_run`](tools/inspect-workflow-run.md) | Inspecte un run de workflow par `workflowRunId` |

### Outils d'administration d'agents

| Outil | Description |
|-------|-------------|
| [`update_agent_system_prompt`](tools/update-agent-system-prompt.md) | Propose une nouvelle version de prompt (HITL Guardrail #3) |

### Outils Sandbox (Phase 10)

Ces outils constituent le cycle de vie complet pour les tests autonomes :

| Outil | Description |
|-------|-------------|
| [`create_sandbox_preset`](tools/sandbox.md#create_sandbox_preset) | Crée un preset temporaire |
| [`create_sandbox_agent`](tools/sandbox.md#create_sandbox_agent) | Crée un agent temporaire |
| [`create_sandbox_workflow`](tools/sandbox.md#create_sandbox_workflow) | Assemble un pipeline multi-agents temporaire |
| [`cleanup_sandbox`](tools/sandbox.md#cleanup_sandbox) | Supprime toutes les entités sandbox |

## Prérequis

- `synapse-core` installé et configuré
- `PermissionCheckerInterface` implémentée (les outils vérifient `canAccessAdmin()`)
- Un client MCP (ex : Claude Desktop) connecté via le transport HTTP ou stdio

## Pattern Sandbox

Le **pattern sandbox** est le mécanisme central de la Phase 10. Il permet à un client MCP de créer des entités temporaires (preset + agent + workflow) pour tester une configuration complète, puis de tout nettoyer en une seule opération.

Les entités sandbox sont :

- **invisibles dans l'admin et le chat** (filtrées par `findAllActive()`, `findAllOrdered()`, `findAllPresets()`)
- **résolvables pour l'exécution** (`findByKey()` et `findActiveByKey()` ne filtrent pas `isSandbox`)
- **nettoyables en bloc** via `cleanup_sandbox`

Voir [Sandbox — guide complet](tools/sandbox.md).

## Sécurité

Tous les outils MCP vérifient `PermissionCheckerInterface::canAccessAdmin()` avant toute opération. Si l'utilisateur MCP n'a pas les droits admin, l'outil retourne :

```json
{
  "status": "error",
  "error": "Access denied. Admin role required."
}
```

Voir [PermissionCheckerInterface](../../core/docs/reference/contracts/permission-checker-interface.md).

### Le piège du transport MCP public

Par construction, le transport HTTP MCP (`/_mcp`) est généralement déclaré `PUBLIC_ACCESS` dans la config Symfony de l'application hôte — la raison : un client MCP n'a pas de cookie de session HTTP, et injecter une auth custom par header dépasse le scope du protocole. Conséquence piégeuse : **sans utilisateur HTTP authentifié, `canAccessAdmin()` retourne `false` et tous les outils MCP sauf `list_agents` refusent l'exécution**, ce qui rend la majorité du package inutilisable dans sa configuration par défaut.

Pour débloquer ce scénario, `synapse-core` expose depuis 2026-04 le flag :

```yaml
# config/packages/synapse.yaml
synapse:
    security:
        mcp_trusted: true
```

**Ce que fait le flag :** quand il est actif, `DefaultPermissionChecker::canAccessAdmin()` retourne `true` si et seulement si la requête courante cible la route `/_mcp` (détection via `RequestStack`). Le bypass est **strictement scopé à la route MCP** : les routes admin HTTP et autres surfaces qui appelleraient `canAccessAdmin()` restent intactes.

**Prérequis de sécurité :** le transport MCP lui-même doit être protégé. En pratique cela signifie :

- exposer `/_mcp` uniquement sur localhost ou sur un réseau interne (Docker network, VPN…)
- ne **jamais** activer ce flag si `/_mcp` est accessible depuis l'internet public sans couche d'authentification additionnelle
- une alerte `E_USER_NOTICE` est émise au boot si le flag est activé, pour signaler le comportement

**Pourquoi un flag plutôt qu'une auth MCP canonique ?** Le protocole MCP ne définit pas encore de couche d'authentification portable (token bearer, mTLS…). Le flag est une solution pragmatique pour les bacs à sable mono-utilisateur et les environnements de dev. Pour un déploiement multi-tenant avec LLMs tiers qui consomment un MCP partagé, il faudra introduire soit un système de tokens signés au niveau transport, soit une impersonation de `SystemUser` dédié.

**Debug rapide si le flag ne prend pas :**

1. `rm -rf var/cache/dev/*` puis `bin/console cache:warmup` (le DI Symfony met en cache les définitions de services)
2. Vérifier que `synapse.security.mcp_trusted` est bien à `true` dans `bin/console debug:container --parameter=synapse.security.mcp_trusted`
3. Vérifier que le chemin détecté est bien `/_mcp` (si la route MCP est customisée via `mcp.http.path`, adapter la détection dans `DefaultPermissionChecker::isMcpRequest()`)
