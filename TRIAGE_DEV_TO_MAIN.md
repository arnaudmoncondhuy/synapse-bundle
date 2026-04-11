# Triage fonctionnalités synapse-v2-roadmap → main

**Branche dev** : `synapse-v2-roadmap`  
**Référence main** : `f522309` (useMasterPrompt)  
**Date** : 2026-04-11

Cocher `[x]` au fur et à mesure des transferts.

---

## Légende

| Symbole | Signification |
|---------|--------------|
| ✅ À migrer | Stable, testé, valeur claire |
| ⚠️ À discuter | Utile mais condition ou risque à lever d'abord |
| ❌ Ne pas migrer | Expérimental, incomplet, ou prévu pour être supprimé |

---

## Fonctionnalités

---

### [ ] Éphémères visibles dans l'admin + MCP trusted
**Statut** : ✅ À migrer  
**Ce que ça fait** : Les workflows éphémères (générés à la volée) apparaissent dans l'admin pour le debug. Flag `mcp_trusted` dans la config pour que les tools MCP ne refusent pas en "Admin role required".  
**Pourquoi migrer** : Socle de plusieurs autres fonctionnalités. MCP trusted est documenté et nécessaire dès qu'on utilise le MCP.

---

### [ ] Tracking des coûts LLM par appel
**Statut** : ✅ À migrer  
**Ce que ça fait** : Chaque appel LLM est tracé en base avec son coût. Les coûts sont agrégés au niveau du workflow run. Visible dans l'admin.  
**Pourquoi migrer** : Observabilité pure, aucune logique métier modifiée. Utile en production dès le premier jour.

---

### [ ] ArchitectAgent + tool MCP `run_architect_proposal`
**Statut** : ✅ À migrer  
**Ce que ça fait** : L'ArchitectAgent génère des définitions d'agents ou de workflows depuis une description en langage naturel (JSON mode). Le tool MCP permet de l'invoquer depuis l'extérieur.  
**Pourquoi migrer** : Fonctionnel et stable. Le K2 (schema config wrapper) doit être migré en même temps.

---

### [ ] Schema config wrapper pour la génération LLM de workflows (K2)
**Statut** : ✅ À migrer — **à migrer avec ArchitectAgent**  
**Ce que ça fait** : Les champs spécifiques à chaque type de step sont encapsulés dans un objet `config` dans le schéma JSON envoyé au LLM. Sans ça, les LLMs open-source génèrent des structures invalides.  
**Pourquoi migrer** : Corrige un bug de production réel. L'ArchitectAgent sans ce fix produit des workflows cassés.

---

### [ ] Retry-with-feedback dans ArchitectAgent
**Statut** : ✅ À migrer — **à migrer avec ArchitectAgent**  
**Ce que ça fait** : Si la proposition de l'ArchitectAgent échoue à la validation, il réessaie avec le message d'erreur en contexte (max 2 retries).  
**Pourquoi migrer** : Amélioration de fiabilité propre et testée. Fait partie du même bloc fonctionnel que l'ArchitectAgent.

---

### [ ] Nouveaux types de steps dans le WorkflowRunner (conditional, parallel, loop, sub_workflow)
**Statut** : ✅ À migrer  
**Ce que ça fait** : 4 nouveaux types de steps en plus du type `agent` de base. Permet des workflows avec branchement conditionnel, exécution parallèle, boucle sur une liste, délégation à un sous-workflow.  
**Pourquoi migrer** : Sans ça, le WorkflowRunner ne fait que des séquences linéaires d'agents. C'est la valeur principale du moteur.

---

### [ ] Éditeur visuel de workflows dans l'admin (workflow builder)
**Statut** : ✅ À migrer  
**Ce que ça fait** : Interface admin pour créer et modifier des workflows visuellement — Stimulus controller, CRUD des 5 types de steps, CSS inspiré n8n.  
**Pourquoi migrer** : Surface légitime pour créer des workflows (par opposition au chat). Stable, fonctionnel, pas de dépendances complexes.

---

### [ ] Page Runs unifiée dans l'admin (liste + graphe coûts 30j)
**Statut** : ✅ À migrer  
**Ce que ça fait** : Page admin listant tous les runs (workflows + agents) avec filtres. Graphe des coûts sur 30 jours.  
**Pourquoi migrer** : UI admin pure, amélioration observabilité. Pas de risque.

---

### [ ] Timeline d'un run + polling live
**Statut** : ✅ À migrer  
**Ce que ça fait** : Détail d'un run avec timeline des steps en temps réel via polling.  
**Pourquoi migrer** : Complète la page Runs. Même catégorie, même niveau de risque.

---

### [x] Encryption obligatoire des credentials et conversations
**Statut** : ✅ **Migré** (commit `d80b098`)  
**Ce que ça fait** : `encryption.key` devient obligatoire dans la config (plus de mode pass-through). 9 services passent en non-nullable.  
**Note de migration** : 836/836 tests OK sur main. Les apps hôtes doivent déclarer `SYNAPSE_ENCRYPTION_KEY` dans leur `.env.local`. La commande `synapse:credentials:encrypt-all` n'a pas été migrée — elle n'était nécessaire que pour la migration initiale sur dev, les nouveaux déploiements chiffrent automatiquement via `ProviderController::encryptCredentials()`.

---

### [x] Provider Anthropic
**Statut** : ✅ **Migré** (commit `f418e80`)  
**Ce que ça fait** : Client HTTP pour l'API Anthropic Messages (Claude). Support streaming SSE, extended thinking, conversion OpenAI ↔ Anthropic, 8 modèles configurés.  
**Note de migration** : copié tel quel depuis dev, s'auto-configure via `Autoconfigure` tag.

---

### [ ] Exécution de code Python (sandbox sidecar)
**Statut** : ⚠️ À discuter  
**Ce que ça fait** : `CodeExecutorInterface` + tool `code_execute` permettant aux agents d'exécuter du Python dans un sandbox isolé (container Docker sidecar `synapse-sandbox`).  
**Condition** : le container sidecar doit être configuré sur l'app hôte. Sans lui, le tool existe mais échoue. **Proposition** : migrer l'interface + `NullCodeExecutor` + le tool ; laisser `HttpCodeExecutor` et le widget sidebar en attente de confirmation usage prod.

---

### [ ] WorkflowRunner en exécution asynchrone (Messenger)
**Statut** : ⚠️ À discuter  
**Ce que ça fait** : Le WorkflowRunner passe en async via Symfony Messenger au lieu de bloquer le process HTTP.  
**Condition** : si le worker Messenger ne tourne pas sur l'app hôte, les workflows ne s'exécutent plus du tout. À migrer seulement après confirmation que basile et lycee_intranet ont le worker configuré.

---

### [ ] Autonomie agent (AbstractPlannerAgent + boucle observe-plan-replan)
**Statut** : ⚠️ À discuter  
**Ce que ça fait** : `AbstractPlannerAgent` avec une boucle autonome observe → plan → replan. VOs `Goal`, `Plan`, `BudgetLimit`. Section `plan` dans la transparency sidebar.  
**Condition** : la boucle replan est la partie la plus expérimentale. **Proposition** : migrer uniquement `BudgetLimit`, `Goal`, `Plan` (VOs utiles partout) et `AbstractPlannerAgent` comme classe de base ; laisser la boucle replan et le `demo_planner` sur dev.

---

### [ ] Replay d'un step avec capture d'input snapshot
**Statut** : ⚠️ À discuter  
**Ce que ça fait** : Possibilité de rejouer un step spécifique d'un run en recapturant l'input exact du moment de l'exécution initiale.  
**Condition** : fonctionnalité avancée de debug. Non critique. À migrer après que le reste de la page Runs soit stable.

---

### ~~ChatIntentRouter + widget HITL sidebar~~
**Statut** : ❌ Ne pas migrer  
**Ce que ça fait** : Détection de commandes de méta-création dans le chat ("crée-moi un workflow X") via regex, suivi d'un widget dans la sidebar avec boutons Inspecter / Promouvoir / Rejeter.  
**Pourquoi ne pas migrer** : Ce pattern est explicitement remplacé dans la nouvelle vision (generate_workflow tool). Le migrer sur main serait du code immédiatement obsolète.

---

### ~~Phase 1 ArchitectAgent — enrichissement contexte (mémoires + agents + tools)~~
**Statut** : ❌ Ne pas migrer pour l'instant  
**Ce que ça fait** : Injecte les mémoires utilisateur, la liste des agents et des tools disponibles dans le prompt de l'ArchitectAgent pour éviter les hallucinations.  
**Pourquoi attendre** : Incomplet (non commité) et couplé au Phase 2 (`generate_workflow` tool) qui n'est pas commencé. À reprendre quand le pivot agentique sera lancé.

---

## Synthèse rapide

| Catégorie | Nb fonctionnalités |
|-----------|-------------------|
| ✅ Migrées | 2 |
| ✅ À migrer (prêtes) | 8 |
| ⚠️ À discuter (conditions) | 4 |
| ❌ Ne pas migrer | 2 |

## Historique des migrations

| Date | Commit | Fonctionnalité |
|------|--------|----------------|
| 2026-04-11 | `d80b098` | Encryption obligatoire |
| 2026-04-11 | `f418e80` | Provider Anthropic |
