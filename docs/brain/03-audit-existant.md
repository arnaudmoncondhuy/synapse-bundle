# Audit de l'existant

> Cartographie du bundle Synapse au moment du démarrage du chantier (mai 2026, branche `brain` créée depuis `main`). Source : exploration directe du code par sous-agent. Tous les chemins partent de `packages/{admin,core,chat}/src/`.

> ⚠️ Cet audit est **figé à la date du chantier**. Les chemins peuvent évoluer ; en cas de doute, regrep le code.

## Distinction app hôte vs corpus de test

- **App hôte technique** : [`basile`](../../../basile) (Symfony hôte qui monte les packages en symlinks dans `vendor/`, voir AGENTS.md §1 et `.agents/context.md`). C'est dans `basile` qu'on teste l'intégration end-to-end du bundle.
- **Corpus de données** : `stacks/weecom` fournit des données réelles (deals, persons, organizations, activités, notes, calls Ringover, factures Abby) qu'on utilise pour **dogfooder** Brain v3, mais qui n'est ni hôte ni cible architecturale. Brain reste 100% agnostique du métier weecom.

## Namespaces et emplacement des nouveaux composants

Toutes les classes Brain vivent dans `packages/core/src/Brain/` sous le namespace `ArnaudMoncondhuy\SynapseCore\Brain\*`. Pas de nouveau package — le brain est une **sous-aire** de `core`, pas un sous-bundle séparé.

```
packages/core/src/Brain/
├── Neuron/           # 7 entités d'aires (Episodic, Semantic, Encyclopedic, ...)
├── Synapse.php       # connexion polymorphe 5 dimensions
├── MemorySource.php  # entité commune
├── Service/          # MemoryExtractor, MemoryRetriever, ConsolidationGate, ...
├── Event/            # Subscribers (BrainContextSubscriber en ENRICH)
├── Tool/             # BrainLinkTool, BrainReinforceTool, ...
└── Contract/         # MemoryFragment, LearningSignal, ...
```

## Vue d'ensemble

Le bundle est un **meta-package** avec 3 sous-packages autonomes :

- `packages/admin` — UI admin, contrôleurs back-office, Twig
- `packages/core` — moteur LLM, mémoire, RAG, accounting, governance
- `packages/chat` — UI chat, API conversation publique

22 entités Doctrine + 1 VO + ~30 services + 8 subscribers + 2 tools builtin + 14 tools MCP.

## Entités à migrer vers `syn_brain_*`

| Entité actuelle | Table actuelle | Devient | Aire cible |
|---|---|---|---|
| `SynapseMessage` (`packages/core/src/Storage/Entity/SynapseMessage.php`) | `synapse_message` | `Brain\Neuron\Episodic` | Hippocampe |
| `SynapseConversation` (`packages/core/src/Storage/Entity/SynapseConversation.php`) | `synapse_conversation` | `Brain\Conversation` | Conteneur épisodique |
| `SynapseVectorMemory` (`packages/core/src/Storage/Entity/SynapseVectorMemory.php`) | `synapse_vector_memory` | `Brain\Neuron\Semantic` | Néocortex |
| `SynapseRagDocument` (`packages/core/src/Storage/Entity/SynapseRagDocument.php`) | `synapse_rag_document` | `Brain\Neuron\Encyclopedic` | Cortex temporal |
| `SynapseRagSource` (`packages/core/src/Storage/Entity/SynapseRagSource.php`) | `synapse_rag_source` | `Brain\MemorySource` (provider='rag') | Source amont |
| `SynapseWorkflow` (`packages/core/src/Storage/Entity/SynapseWorkflow.php`) | `synapse_workflow` | `Brain\Neuron\Procedural` | Ganglions |

**Nouvelles entités** (pas d'équivalent existant) :

- `Brain\Neuron\Emotional` — Amygdale (valence, intensity, target poly)
- `Brain\Neuron\Sensory` — Cortex sensoriel (input buffer transitoire)
- `Brain\Neuron\Motor` — Cortex moteur (output planifié)
- `Brain\Synapse` — connexion polymorphe 5-dimensions
- `Brain\LearningSignal` — événements d'apprentissage
- `Brain\FunctionalNetwork` — modes contextuels
- `Brain\AuditLog` — traçabilité Palantir-style

## Entités qui restent en `syn_core_*` (renommées seulement)

| Entité | Table actuelle | Devient |
|---|---|---|
| `SynapseAgent` | `synapse_agent` | `syn_core_agent` |
| `SynapseAgentPromptVersion` | `synapse_agent_prompt_version` | `syn_core_agent_prompt_version` |
| `SynapseProvider` | `synapse_provider` | `syn_core_provider` |
| `SynapseModel` | `synapse_model` | `syn_core_model` |
| `SynapseModelPreset` | `synapse_model_preset` | `syn_core_model_preset` |
| `SynapseLlmCall` | `synapse_llm_call` | `syn_core_llm_call` |
| `SynapseSpendingLimit` + log | `synapse_spending_limit*` | `syn_core_spending_limit*` |
| `SynapseDebugLog` | `synapse_debug_log` | `syn_core_debug_log` |
| `SynapseConfig` | `synapse_config` | `syn_core_config` |
| `SynapseTone` | `synapse_tone` | `syn_core_tone` |
| `SynapseToolConfig` | `synapse_tool_config` | `syn_core_tool_config` |
| `SynapseCodeExecution` | `synapse_code_execution` | `syn_core_code_execution` |
| `SynapseMessageAttachment` | `synapse_message_attachment` | `syn_core_message_attachment` (rattaché à neuron_episodic) |
| `SynapseAgentTestCase` | `synapse_agent_test_case` | `syn_core_agent_test_case` |
| `SynapseWorkflowRun` | `synapse_workflow_run` | `syn_core_workflow_run` (exécution = log, pas neurone) |

Test simple appliqué : *"est-ce que ça pense, ça mémorise, ça apprend ?"*. Si non → `core_`.

## Services à refondre

### Mémoire / RAG (refonte profonde)

- `MemoryManager` (`packages/core/src/Memory/MemoryManager.php`) — orchestrateur sémantique. **Sera scindé** : `MemoryExtractor` (multi-aires) + `MemoryWriter` (par aire) + `MemoryRetriever` (spreading activation).
- `RagManager` (`packages/core/src/Rag/RagManager.php`) — orchestrateur RAG. **Sera fondu** dans le `MemoryExtractor` côté encyclopédique.
- `EmbeddingService` (`packages/core/src/Service/EmbeddingService.php`) — reste, devient utilitaire par aire (4 aires sur 7 ont embedding).
- `ChunkingService` (`packages/core/src/Service/ChunkingService.php`) — reste, spécifique aire encyclopédique.
- `RagSourceRegistry` (`packages/core/src/Rag/RagSourceRegistry.php`) — devient `MemorySourceRegistry` (provider-agnostique).
- `ProposeMemoryTool` (`packages/core/src/Memory/Tool/ProposeMemoryTool.php`) — étendu vers `BrainLinkTool`, `BrainReinforceTool` (cf. design §10).

### Subscribers liés à la mémoire (refonte)

- `MemoryContextSubscriber` (`packages/core/src/Event/MemoryContextSubscriber.php`) → `BrainContextSubscriber` (spreading activation au lieu de vector search)
- `RagContextSubscriber` (`packages/core/src/Event/RagContextSubscriber.php`) → absorbé dans `BrainContextSubscriber`

**Point d'insertion dans le pipeline** : phase `ENRICH` (cf. `packages/core/docs/explanation/architecture.md`). Le `BrainContextSubscriber` remplace les deux subscribers existants au même point — le pipeline 5 phases (BUILD → ENRICH → OPTIMIZE → FINALIZE → CAPTURE) reste intact. ChatService et PromptPipeline n'ont rien à savoir du Brain.

### Subscribers qui restent (peu ou pas de changement)

- `ContextBuilderSubscriber` — orchestrateur d'enrichissement, reste
- `ContextTruncationSubscriber` — gestion taille, reste
- `ToolExecutionSubscriber` — exécution tools, reste
- `DebugLogSubscriber` — logs, reste (sortie vers `syn_core_debug_log`)
- `MasterPromptSubscriber` — system prompt, reste
- `AttachmentRemovalSubscriber` — cleanup, reste

### ChatService, Pipeline, MultiTurnExecutor

`ChatService`, `PromptPipeline`, `MultiTurnExecutor`, `ContextTruncationService`, `ToolRegistry`, `ToolExecutor`, `LlmClientRegistry`, `TokenAccountingService` : **restent en `Core/Engine`**. Le brain est un consommateur du pipeline, pas l'inverse. Le pipeline ne sait pas que le brain existe — il appelle des Context Providers, dont l'un est `BrainContextProvider`.

## Tools existants

### Tag `synapse.tool` (2 builtin)

- `ProposeMemoryTool` → à étendre (cf. supra)
- `CodeExecuteTool` → reste en core, pas brain

### Tag `mcp.tool` (14, admin via MCP)

Liste rapide (pour info) : agent CRUD (6), preset CRUD (4), workflow CRUD (5), utilities (2). Ces tools ne changent pas dans le scope Brain v3 — ils continuent d'éditer des objets `syn_core_*`.

## Tables Doctrine — préfixe actuel

Préfixe **hardcodé** dans les annotations `#[ORM\Table(name: 'synapse_...')]`. Pas de config dynamique aujourd'hui.

**Action jalon 1** : introduire `synapse.persistence.table_prefix` (défaut `syn_`) via un `TablePrefixSubscriber` Doctrine. Cf. [ADR-001](05-decisions/001-prefixe-table-configurable.md).

## Migrations Doctrine

**Aucune migration dans le bundle**. Les apps hôtes gèrent leurs propres migrations via `doctrine:migration:*`. Pour Brain v3, on devra :

1. Fournir des SQL scripts de migration `migrations-brain-v3/` pour les apps hôtes
2. Documenter dans le bundle ce qu'une app doit faire pour migrer (script de data migration épisodique/sémantique/encyclopédique)
3. À voir : utiliser DoctrineMigrationsBundle dans le bundle lui-même ? (ADR jalon 1)

## Tests existants

- 142 tests Unit dans `packages/core/tests/Unit/`
- 4 tests `packages/chat/tests/`
- 6 tests `packages/admin/tests/`
- Meilleure couverture : RAG (RagManagerTest, ChunkingTest), Memory (MemoryManagerTest), Engine (ChatService tests), MessageHandler

**Stratégie Brain v3** : ne pas casser les tests existants en `core/`. Les tests `Memory/*Test.php` seront migrés vers `tests/Unit/Brain/` au fur et à mesure. Les anciens fichiers sont supprimés (pas de compat layer = pas de double maintenance).

## Ce qui meurt explicitement

- `SynapseVectorMemory` (entité) → remplacée par `Brain\Neuron\Semantic`
- `SynapseRagDocument` (entité) → remplacée par `Brain\Neuron\Encyclopedic`
- `MemoryManager` (service monolithique) → scindé
- `RagManager` (service séparé) → fondu dans MemoryExtractor
- `RagContextSubscriber` → absorbé
- Tables `synapse_*` → renommées `syn_core_*` ou `syn_brain_*`

## Ce qui ne bouge pas (hors-scope du chantier brain)

- Tout `syn_core_*` (agents, providers, models, presets, accounting, governance, workflows runs)
- Pipeline LLM (`ChatService`, `PromptPipeline`, `MultiTurnExecutor`, etc.)
- Tools MCP admin
- Sécurité, encryption credentials, doctor command
- Asset mapper, Twig, Stimulus controllers de l'admin

---

**Suite** :
- Décisions ouvertes sur l'audit → ADRs au jalon 1 (préfixe table, gestion migrations)
- Plan détaillé jalon 1 : [06-phases/jalon-1-fondations.md](06-phases/jalon-1-fondations.md)
