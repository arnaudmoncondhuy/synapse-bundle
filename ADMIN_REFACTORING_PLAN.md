# 🧠 ADMIN_REFACTORING_PLAN.md — Synapse Admin V2

> **Auteur :** Architecte Synapse  
> **Date :** 2026-02-26  
> **Statut :** 🟢 En cours d'implémentation — Section **Intelligence** terminée  
> **Objectif :** Refonte complète du panneau d'administration Synapse sous `/synapse/admin-v2`, découplé du Core pour une future extraction en `synapse-admin-bundle`.

---

## Table des matières

1. [Inventaire de l'Admin V1 actuelle](#1-inventaire-de-ladmin-v1-actuelle)
2. [Proposition de navigation V2](#2-proposition-de-navigation-v2)
3. [Métriques & réglages manquants](#3-métriques--réglages-manquants)
4. [Architecture de dossiers V2](#4-architecture-de-dossiers-v2)
5. [Stratégie UX Dual-Mode](#5-stratégie-ux-dual-mode)
6. [Feuille de route d'implémentation](#6-feuille-de-route-dimplémentation)

---

## 1. Inventaire de l'Admin V1 actuelle

### 1.1 Contrôleurs (14 fichiers)

| # | Contrôleur | Route | Responsabilité | Actions |
|---|-----------|-------|---------------|---------|
| 1 | `DashboardController` | `/synapse/admin` | Vue d'ensemble KPIs | `dashboard()` |
| 2 | `AnalyticsController` | `/synapse/admin/analytics` | Stats d'usage détaillées | `index()` |
| 3 | `ProvidersController` | `/synapse/admin/providers` | Gestion credentials LLM | `index()`, `edit()`, `test()` |
| 4 | `ModelsController` | `/synapse/admin/models` | Catalogue modèles LLM | `index()`, `toggle()`, `updatePricing()` |
| 5 | `PresetsController` | `/synapse/admin/presets` | Configuration presets LLM | `index()`, `new()`, `edit()`, `activate()`, `clone()`, `delete()` |
| 6 | `PresetTestController` | `/synapse/admin/presets/{id}/test` | Validation de presets via agent IA | `test()`, `status()` |
| 7 | `EmbeddingSettingsController` | `/synapse/admin/embeddings` | Config RAG/Embeddings | `index()`, `testEmbedding()` |
| 8 | `ToolsController` | `/synapse/admin/tools` | Catalogue outils exposés | `index()`, `show()` |
| 9 | `SettingsController` | `/synapse/admin/settings` | Paramètres globaux | `index()` |
| 10 | `DebugController` | `/synapse/_debug/{id}` | Rapport de debug individuel | `show()` |
| 11 | `DebugLogsController` | `/synapse/admin/debug-logs` | Journal des logs de debug | `index()`, `clear()` |
| 12 | `ConversationController` | `/synapse/admin/conversations` | Break-Glass (accès conversations) | `view()` |
| 13 | `ChatUiController` | `/synapse/chat` | Interface de chat intégrée | `__invoke()` |
| 14 | `AbstractAdminController` | — | Contrôleur abstrait legacy (à étendre) | `dashboard()`, `analytics()`, `config()` — **⚠️ déprécié** |

### 1.2 Templates Twig (22 fichiers)

**Répertoire :** `src/Infrastructure/Resources/views/`

| Catégorie | Templates |
|-----------|-----------|
| **Layout** | `admin/layout.html.twig` (standalone), `admin/layout_module.html.twig` (intégré), `admin/_layout.html.twig` |
| **Dashboard** | `admin/dashboard.html.twig` |
| **Analytics** | `admin/analytics.html.twig` |
| **Providers** | `admin/providers.html.twig`, `admin/provider_edit.html.twig` |
| **Modèles** | `admin/models.html.twig` |
| **Presets** | `admin/presets.html.twig`, `admin/preset_edit.html.twig`, `admin/preset_test_waiting.html.twig`, `admin/preset_test_result.html.twig` |
| **Embeddings** | `admin/embeddings.html.twig` |
| **Outils** | `admin/tools.html.twig`, `admin/tool_show.html.twig` |
| **Paramètres** | `admin/settings.html.twig` |
| **Debug** | `admin/debug_logs.html.twig`, `debug/show.html.twig` |
| **Conversations** | `admin/conversation.html.twig` |
| **Chat** | `chat/page.html.twig`, `chat/component.html.twig`, `chat/sidebar.html.twig` |

### 1.3 Assets CSS (3 fichiers)

| Fichier | Rôle | Lignes |
|---------|------|--------|
| `synapse-variables.css` | Design tokens (couleurs HSL, spacing, shadows, typographie) — overridables via `--custom-*` | 76 |
| `synapse-admin.css` | Composants de base (layout, sidebar, cards, grids, buttons, badges, forms, tables, alerts, KPIs, responsive) | 507 |
| `synapse-admin-enhancements.css` | Améliorations visuelles (cards améliorées, smart presets, collapsibles, sticky save, activity chart) | 661 |

### 1.4 JavaScript (2 contrôleurs Stimulus)

| Fichier | Rôle |
|---------|------|
| `synapse_chat_controller.js` | Logique du widget de chat (SSE, Markdown, historique) |
| `synapse_sidebar_controller.js` | Gestion du sidebar (conversations) |

### 1.5 Services support

| Classe | Rôle |
|--------|------|
| `SynapseLayoutResolver` | Résolution dynamique du layout (standalone vs module) |
| `SynapseTwigExtension` | Fonctions Twig : `synapse_admin_layout()`, `synapse_config()`, `synapse_version()`, `synapse_markdown` |
| `SynapseRuntime` | Rendu du widget chat, lecture de version |
| `AdminSecurityTrait` | Vérification d'accès admin + validation CSRF |
| `DoctrineAdminLogger` | Logger en base pour le debug admin |

### 1.6 Navigation actuelle (sidebar)

```
📊 Monitoring
├── Dashboard (KPIs)
└── Analytics (stats d'usage)

⚙️ Configuration
├── Providers (credentials LLM)
├── Modèles (catalogue)
├── Presets (config génération)
├── Embeddings (RAG/stockage vectoriel)
├── Outils (tools exposés)
├── Paramètres (globaux)
└── Debug Logs (journaux)
```

### 1.7 KPIs actuels du Dashboard

| KPI | Source |
|-----|--------|
| Conversations actives (24h) | `SynapseConversationRepository::countActiveLast24h()` |
| Utilisateurs actifs (24h) | `SynapseConversationRepository::countActiveUsersSince()` |
| Tokens consommés (7j) | `SynapseTokenUsageRepository::getGlobalStats()` |
| Coût estimé (7j) | `SynapseTokenUsageRepository::getGlobalStats()` |
| Souvenirs mémorisés | `SynapseVectorMemoryRepository::count()` |
| Usage quotidien (30j, graphique) | `SynapseTokenUsageRepository::getDailyUsage()` |
| Providers actifs | `SynapseProviderRepository::findAll()` (filtrés enabled + configured) |
| Preset actif | `SynapsePresetRepository::findActive()` |

### 1.8 Réglages actuels

| Page | Réglages |
|------|----------|
| **Settings** | Rétention RGPD (jours), Langue du contexte, Prompt système, Mode debug |
| **Presets** | Provider, Modèle, Température, Top-P, Max tokens, Prompt système, JSON mode, providerOptions dynamiques |
| **Providers** | Label, Activation on/off, Credentials dynamiques (chiffrées), Test de connectivité |
| **Models** | Activation on/off, Pricing input/output, Label custom |
| **Embeddings** | Provider d'embedding, Modèle, Dimension, Stratégie de chunking, Chunk size/overlap, Vector store |

---

## 2. Proposition de navigation V2

### 2.1 Philosophie

Regrouper les entrées par **domaines métier** parlants plutôt que par type technique. Les labels doivent résonner avec des non-techniciens : « Intelligence » plutôt que « Providers/Models ».

### 2.2 Arborescence proposée

```
🏠 Tableau de bord
   └── Vue d'ensemble (KPIs, santé système, alertes)

🧠 Intelligence — "Comment l'IA pense"
   ├── Fournisseurs        (ex-Providers : credentials, activation, test)
   ├── Modèles             (ex-Models : catalogue, pricing, activation)
   ├── Presets              (configuration de génération)
   └── Personas             [NOUVEAU] (gestion visuelle des personas)

💬 Conversation — "Comment l'IA dialogue"
   ├── Paramètres           (langue contexte, prompt système, rétention RGPD)
   ├── Outils               (ex-Tools : catalogue des function calls)
   └── Historique           [NOUVEAU] (liste/recherche conversations, break-glass)

🧩 Mémoire — "Ce que l'IA retient"
   ├── Embeddings           (config RAG/vectoriel)
   ├── Documents            [NOUVEAU] (gestion documents sources RAG)
   └── Souvenirs            [NOUVEAU] (visualisation des mémoires utilisateur)

📈 Usage — "Ce que ça coûte"
   ├── Analytics            (graphiques, stats par module/modèle)
   ├── Quotas               [NOUVEAU] (limites par user/équipe/global)
   └── Export               [NOUVEAU] (export CSV/JSON des données d'usage)

🔒 Sécurité — "La maîtrise"
   ├── Clés API             [NOUVEAU] (gestion centralisée des secrets)
   ├── Audit & Logs         (debug logs + journal d'accès break-glass)
   └── RGPD                 [NOUVEAU] (purge, anonymisation, consentements)

⚙️ Système
   ├── Debug                (rapports de debug échanges)
   ├── Santé                [NOUVEAU] (health checks : DB, cache, providers, queues)
   └── À propos             (version, dépendances, changelog)
```

### 2.3 Mapping V1 → V2

| Page V1 | Section V2 | Notes |
|----------|-----------|-------|
| Dashboard | 🏠 Tableau de bord | KPIs enrichis + alertes |
| Analytics | 📈 Usage > Analytics | Inchangé |
| Providers | 🧠 Intelligence > Fournisseurs | Renommé |
| Models | 🧠 Intelligence > Modèles | Renommé |
| Presets | 🧠 Intelligence > Presets | Inchangé |
| Embeddings | 🧩 Mémoire > Embeddings | Déplacé |
| Tools | 💬 Conversation > Outils | Déplacé |
| Settings | 💬 Conversation > Paramètres | Déplacé et éclaté |
| Debug Logs | 🔒 Sécurité > Audit & Logs | Déplacé |
| Conversation (break-glass) | 💬 Conversation > Historique | Enrichi (liste) |

---

## 3. Métriques & réglages manquants

### 3.1 Métriques à ajouter

| Métrique | Catégorie V2 | Valeur métier |
|----------|-------------|---------------|
| **Latence moyenne par provider** | Intelligence > Fournisseurs | Comparaison des performances |
| **Taux d'erreur par provider** | Intelligence > Fournisseurs | Fiabilité |
| **Top 5 conversations les plus longues** | Conversation > Historique | Identifier les cas heavy-use |
| **Nombre de function calls par outil** | Conversation > Outils | Usage réel des outils |
| **Taux de confirmation mémoire** | Mémoire > Souvenirs | Ratio proposé/confirmé |
| **Volume de documents indexés** | Mémoire > Documents | Suivi RAG |
| **Coût par utilisateur** | Usage > Analytics | Ventilation fine |
| **Quota restant** | Usage > Quotas | Prévention de dépassements |
| **Score de santé global** | Système > Santé | Vue synthétique (vert/jaune/rouge) |
| **Uptime des providers** | Système > Santé | Monitoring temps réel |

### 3.2 Réglages à ajouter

| Réglage | Section V2 | Description |
|---------|-----------|-------------|
| **Gestion des Personas** | Intelligence > Personas | CRUD visuel (nom, avatar, prompt, tone of voice) |
| **Quotas par utilisateur** | Usage > Quotas | Limite tokens/jour, tokens/mois, coût max |
| **Limites de rate par provider** | Intelligence > Fournisseurs | Requests/minute, fallback automatique |
| **Politique de rétention par scope** | 🔒 RGPD | Différencier rétention conversations vs mémoire |
| **Webhooks / notifications** | Système | Alertes Slack/Mattermost sur événements critiques |
| **Mode maintenance** | Système | Désactiver temporairement l'IA avec message custom |
| **Blacklist de mots/sujets** | Conversation > Paramètres | Filtrage de contenu (content safety) |
| **Config multi-presets** | Intelligence > Presets | Routing intelligent : preset par user/rôle/contexte |
| **Paramètres d'export** | Usage > Export | Format, fréquence, destination |
| **Health check endpoints** | Système > Santé | URLs et fréquences de vérification |

---

## 4. Architecture de dossiers V2

### 4.1 Principe : isolation totale

La V2 vit dans un namespace dédié (`AdminV2`), avec ses propres contrôleurs, templates et assets. Aucune classe de la V2 n'importe directement depuis `Admin\Controller\*` (V1). Le couplage avec le Core se fait **uniquement** via les interfaces et services du Core.

### 4.2 Structure proposée

```
src/
├── Admin/                          ← V1 (inchangée, dépréciée progressivement)
│   ├── Controller/                 ← 14 contrôleurs actuels
│   ├── Layout/
│   └── Twig/
│
├── AdminV2/                        ← 🆕 V2 découplée
│   ├── Controller/
│   │   ├── DashboardController.php
│   │   ├── Intelligence/
│   │   │   ├── ProviderController.php
│   │   │   ├── ModelController.php
│   │   │   ├── PresetController.php
│   │   │   └── PersonaController.php
│   │   ├── Conversation/
│   │   │   ├── SettingsController.php
│   │   │   ├── ToolController.php
│   │   │   └── HistoryController.php
│   │   ├── Memory/
│   │   │   ├── EmbeddingController.php
│   │   │   ├── DocumentController.php
│   │   │   └── MemoryController.php
│   │   ├── Usage/
│   │   │   ├── AnalyticsController.php
│   │   │   ├── QuotaController.php
│   │   │   └── ExportController.php
│   │   ├── Security/
│   │   │   ├── AuditController.php
│   │   │   ├── ApiKeyController.php
│   │   │   └── GdprController.php
│   │   └── System/
│   │       ├── DebugController.php
│   │       ├── HealthController.php
│   │       └── AboutController.php
│   │
│   ├── Twig/
│   │   ├── AdminV2Extension.php     ← Functions Twig V2
│   │   └── AdminV2Runtime.php
│   │
│   ├── Layout/
│   │   └── LayoutResolver.php       ← Résolution layout V2
│   │
│   ├── EventSubscriber/             ← Pour des hooks admin-only
│   │   └── AdminMenuSubscriber.php
│   │
│   └── DependencyInjection/         ← Préparation future bundle séparé
│       └── AdminV2Extension.php
│
├── Infrastructure/
│   └── Resources/
│       └── views/
│           ├── admin/              ← Templates V1 (inchangés)
│           └── admin_v2/           ← 🆕 Templates V2
│               ├── layout/
│               │   ├── base.html.twig
│               │   ├── _sidebar.html.twig
│               │   ├── _header.html.twig
│               │   └── _flash.html.twig
│               ├── dashboard/
│               │   └── index.html.twig
│               ├── intelligence/
│               │   ├── providers.html.twig
│               │   ├── provider_edit.html.twig
│               │   ├── models.html.twig
│               │   ├── presets.html.twig
│               │   ├── preset_edit.html.twig
│               │   └── personas.html.twig
│               ├── conversation/
│               │   ├── settings.html.twig
│               │   ├── tools.html.twig
│               │   ├── tool_show.html.twig
│               │   └── history.html.twig
│               ├── memory/
│               │   ├── embeddings.html.twig
│               │   ├── documents.html.twig
│               │   └── memories.html.twig
│               ├── usage/
│               │   ├── analytics.html.twig
│               │   ├── quotas.html.twig
│               │   └── export.html.twig
│               ├── security/
│               │   ├── audit.html.twig
│               │   ├── api_keys.html.twig
│               │   └── gdpr.html.twig
│               └── system/
│                   ├── debug.html.twig
│                   ├── health.html.twig
│                   └── about.html.twig
│
assets/
├── styles/
│   ├── admin/                      ← CSS V1 (inchangé)
│   └── admin-v2/                   ← 🆕 CSS V2
│       ├── _variables.css           ← Design tokens V2
│       ├── _reset.css               ← Reset scopé
│       ├── _layout.css              ← Grid layout + sidebar
│       ├── _typography.css          ← Typographie
│       ├── components/
│       │   ├── _card.css
│       │   ├── _button.css
│       │   ├── _badge.css
│       │   ├── _form.css
│       │   ├── _table.css
│       │   ├── _alert.css
│       │   ├── _kpi.css
│       │   ├── _collapsible.css     ← Sections pliables (Dual-Mode)
│       │   └── _modal.css
│       └── admin-v2.css             ← Point d'entrée (@import all)
│
├── controllers/                    ← JS Stimulus (inchangé)
│   └── admin-v2/                   ← 🆕 Contrôleurs Stimulus V2
│       ├── sidebar_controller.js
│       ├── collapsible_controller.js
│       ├── chart_controller.js
│       └── form_controller.js
```

### 4.3 Convention de nommage

| Élément | Convention V2 |
|---------|--------------|
| Routes | `synapse_v2_admin_*` (ex: `synapse_v2_admin_dashboard`) |
| CSS namespace | `.sv2-*` (ex: `.sv2-sidebar`, `.sv2-card`) — plus court que `.synapse-admin__` |
| Templates | `@Synapse/admin_v2/…` |
| URL prefix | `/synapse/admin-v2` |
| Services DI tag | `synapse.admin_v2.*` |

### 4.4 Règle d'or : contrôleurs « minces »

Chaque contrôleur V2 doit :
- Vérifier les droits via `AdminSecurityTrait`
- Appeler un service du Core (jamais de logique métier directe)
- Retourner la Response Twig

Exemple type :
```php
#[Route('/synapse/admin-v2/intelligence/providers', name: 'synapse_v2_admin_providers')]
public function index(): Response
{
    $this->denyAccessUnlessAdmin($this->permissionChecker);
    
    $providers = $this->providerService->getAll(); // Core service
    
    return $this->render('@Synapse/admin_v2/intelligence/providers.html.twig', [
        'providers' => $providers,
    ]);
}
```

---

## 5. Stratégie UX Dual-Mode

### 5.1 Principe

Chaque page a deux niveaux de lecture :

| Mode | Cible | Affichage |
|------|-------|-----------|
| **Essentiel** | Novice / décideur | KPIs visuels, boutons d'action principaux, explications contextuelles |
| **Avancé** | Développeur / ops | Sections `<details>` pliables avec paramètres fins (température, top-p, prompts système, JSON mode…) |

### 5.2 Implémentation CSS/JS

```html
<!-- Section toujours visible (mode Essentiel) -->
<div class="sv2-section sv2-section--essential">
    <h3>Configuration rapide</h3>
    <!-- Smart presets visuels (cards radio) -->
</div>

<!-- Section pliable (mode Avancé) -->
<details class="sv2-collapsible" data-controller="collapsible">
    <summary class="sv2-collapsible__trigger">
        <span>⚙️ Paramètres avancés</span>
        <i data-lucide="chevron-down" class="sv2-collapsible__icon"></i>
    </summary>
    <div class="sv2-collapsible__content">
        <!-- Température, Top-P, JSON mode, etc. -->
    </div>
</details>
```

### 5.3 Tooltips contextuels

Chaque réglage avancé affiche une bulle d'aide expliquant l'impact en termes métier :
- ✅ « Température (0.7) : L'IA sera créative mais cohérente. »
- ⚠️ « Température (1.5) : Attention, les réponses seront imprévisibles. »

---

## 6. Feuille de route d'implémentation

### ✅ Étape 1 — Fondations
- [x] Inventaire complet de l'admin V1
- [x] Proposition d'arborescence V2
- [x] Identification des manques
- [x] Architecture de dossiers
- [x] Validation du plan

### ✅ Étape 2 — Layout de base CSS/JS
- [x] Design system `assets/styles/admin-v2/` (variables, reset, layout, components)
- [x] Template `base.html.twig` V2 avec sidebar, topbar et structure responsive
- [x] Rendu validé dans l'environnement Docker

### ✅ Étape 3 — Dashboard V2
- [x] `DashboardController` V2 avec KPIs enrichis
- [x] Graphique d'activité
- [x] Rendu validé

### ✅ Étape 4a — Migration : 🧠 Intelligence

| Page | Contrôleur V2 | Template V2 | Statut |
|------|--------------|-------------|--------|
| Fournisseurs | `ProviderController.php` | `providers.html.twig` | ✅ Terminé |
| Modèles | `ModelController.php` | `models.html.twig` | ✅ Terminé |
| Presets | `PresetController.php` | `presets.html.twig` | ✅ Terminé |

**Routes V2 actives :**
- `synapse_v2_admin_providers` → `/synapse/admin-v2/intelligence/fournisseurs`
- `synapse_v2_admin_models` → `/synapse/admin-v2/intelligence/modeles`
- `synapse_v2_admin_models_toggle` → `/synapse/admin-v2/intelligence/modeles/{modelId}/toggle`
- `synapse_v2_admin_models_pricing` → `/synapse/admin-v2/intelligence/modeles/{modelId}/pricing`
- `synapse_v2_admin_presets` → `/synapse/admin-v2/intelligence/presets`
- `synapse_v2_admin_presets_activate` → `/synapse/admin-v2/intelligence/presets/{id}/activate`

**Design system appliqué :**
- Cartes Glassmorphism via `.sv2-card`, `.sv2-card--active`, `.sv2-card--success`, `.sv2-card--warning`
- Mode Dual-Mode via `.sv2-collapsible/__summary/__content/__chevron`
- Badges sémantiques `.sv2-badge--success/warning/neutral/primary/info`
- Tableau avec `.sv2-table` + `.sv2-table-cell-label` pour les modèles
- Aucun `style=""` inline — 100% classes `.sv2-*`

### ✅ Étape 4b — Migration : 💬 Conversation

| Page | Contrôleur V2 | Template V2 | Statut |
|------|--------------|-------------|--------|
| Paramètres | `Conversation/SettingsController.php` | `conversation/settings.html.twig` | ✅ Terminé |
| Outils | `Conversation/ToolsController.php` | `conversation/tools.html.twig` | ✅ Terminé |
| Historique | `Conversation/HistoryController.php` | `conversation/history.html.twig` | ✅ Terminé |

**Routes V2 actives :**
- `synapse_v2_admin_settings` → `/synapse/admin-v2/conversation/parametres`
- `synapse_v2_admin_tools` → `/synapse/admin-v2/conversation/outils`
- `synapse_v2_admin_history` → `/synapse/admin-v2/conversation/historique`

### ✅ Étape 4c — Migration : 🧩 Mémoire

| Page | Contrôleur V2 | Template V2 | Statut |
|------|--------------|-------------|--------|
| Embeddings | `Memoire/EmbeddingController.php` | `memoire/embeddings.html.twig` | ✅ Terminé |
| Documents | — | — | 🕐 Placeholder |
| Souvenirs | — | — | 🕐 Placeholder |

**Routes V2 actives :**
- `synapse_v2_admin_embeddings` → `/synapse/admin-v2/memoire/embeddings`
- `synapse_v2_admin_embeddings_test` → `/synapse/admin-v2/memoire/embeddings/test` (POST JSON)

### ✅ Étape 4d — Migration : 📈 Usage

| Page | Contrôleur V2 | Template V2 | Statut |
|------|--------------|-------------|--------|
| Analytics | `Usage/AnalyticsController.php` | `usage/analytics.html.twig` | ✅ Terminé |
| Quotas | — | — | 🕐 Placeholder |
| Export | — | — | 🕐 Placeholder |

**Routes V2 actives :**
- `synapse_v2_admin_analytics` → `/synapse/admin-v2/usage/analytics`

### ✅ Étape 4f — Migration : ⚙️ Système

| Page | Contrôleur V2 | Template V2 | Statut |
|------|--------------|-------------|--------|
| Debug LLM | `Systeme/DebugController.php` | `systeme/debug.html.twig` | ✅ Terminé |
| Santé | `Systeme/HealthController.php` | `systeme/health.html.twig` | ✅ Terminé |
| À propos | `Systeme/AboutController.php` | `systeme/about.html.twig` | ✅ Terminé |

**Routes V2 actives :**
- `synapse_v2_admin_debug` → `/synapse/admin-v2/systeme/debug`
- `synapse_v2_admin_debug_clear` → `/synapse/admin-v2/systeme/debug/clear` (POST CSRF)
- `synapse_v2_admin_health` → `/synapse/admin-v2/systeme/sante`
- `synapse_v2_admin_about` → `/synapse/admin-v2/systeme/a-propos`

### ✅ Étape 4e — Migration : 🔒 Sécurité

| Page | Contrôleur V2 | Template V2 | Statut |
|------|--------------|-------------|--------|
| Audit & Logs | `Securite/AuditController.php` | `securite/audit.html.twig` | ✅ Terminé |
| Clés API | `Securite/ApiKeysController.php` | `securite/api_keys.html.twig` | ✅ Terminé |
| RGPD | `Securite/GdprController.php` | `securite/gdpr.html.twig` | ✅ Terminé |

**Routes V2 actives :**
- `synapse_v2_admin_audit` → `/synapse/admin-v2/securite/audit`
- `synapse_v2_admin_api_keys` → `/synapse/admin-v2/securite/cles-api`
- `synapse_v2_admin_gdpr` → `/synapse/admin-v2/securite/rgpd`

### 🔜 Étape 5 — Nouvelles fonctionnalités
- [ ] Personas
- [ ] Documents (RAG)
- [ ] Souvenirs (Mémoire long terme)
- [ ] Quotas par utilisateur
- [ ] Export CSV/JSON

### 🔮 Étape 6 — Extraction en bundle
- [ ] Déplacer `AdminV2/` dans un nouveau repo `synapse-admin-bundle`
- [ ] Créer le `SynapseAdminBundle` avec DI auto-config

---

> **📍 Progrès actuel :** Étapes 4a–4f **toutes terminées** (Intelligence, Conversation, Mémoire, Usage, Système, Sécurité). Corrections : `ConversationManager::getAllConversations()`, variables Twig health, fichier gdpr tronqué. Prochaine étape : Nouvelles fonctionnalités (Personas, Documents, Souvenirs) — Étape 5.

