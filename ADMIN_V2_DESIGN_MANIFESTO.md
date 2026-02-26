# 📐 ADMIN_V2_DESIGN_MANIFESTO.md
## La Bible de l'Interface Synapse Admin V2

> **Ce document est la loi.** Toute ligne de code HTML/Twig touchant à l'Admin V2
> doit respecter ces règles sans exception. Son but : garantir qu'un LLM différent,
> ou un développeur junior, produise un résultat identique à la vision d'origine.

---

## 🚫 LOI N°1 — ZÉRO `style="..."` INLINE

**C'est la règle la plus importante. Elle ne souffre aucune exception.**

```html
<!-- ❌ INTERDIT — jamais, sous aucun prétexte -->
<div style="background: white; border: 1px solid #ccc; padding: 16px;">
<span style="color: green; font-weight: bold;">

<!-- ✅ OBLIGATOIRE -->
<div class="sv2-card sv2-card--success">
<span class="sv2-badge sv2-badge--success sv2-font-bold">
```

**Pourquoi ?** Les styles inline brisent la cohérence du thème, rendent le dark mode impossible,
contournent le design system, et accumulent de la dette technique.

---

## 🎨 LOI N°2 — UNIQUEMENT LES CLASSES `.sv2-*`

Toute décision visuelle passe par une classe utilitaire existante. Si une classe manque,
**ajoutez-la dans le bon fichier CSS** du design system — ne jamais patcher dans le HTML.

### Répertoire des composants disponibles

| Fichier CSS | Classes principales |
|-------------|---------------------|
| `_card.css` | `.sv2-card`, `.sv2-card--hover`, `.sv2-card--active`, `.sv2-card--success`, `.sv2-card--warning`, `.sv2-card--danger`, `.sv2-card--primary` |
| `_card.css` | `.sv2-card__header`, `.sv2-card__header-icon`, `.sv2-card__header-actions`, `.sv2-card__title`, `.sv2-card__subtitle`, `.sv2-card__body`, `.sv2-card__body--compact`, `.sv2-card__body--flush`, `.sv2-card__footer` |
| `_card.css` | `.sv2-empty`, `.sv2-empty__icon`, `.sv2-empty__title`, `.sv2-empty__description` |
| `_button.css` | `.sv2-btn`, `.sv2-btn--primary`, `.sv2-btn--ghost`, `.sv2-btn--outline`, `.sv2-btn--success`, `.sv2-btn--danger`, `.sv2-btn--sm`, `.sv2-btn--lg`, `.sv2-btn--icon` |
| `_badge.css` | `.sv2-badge`, `.sv2-badge--success`, `.sv2-badge--warning`, `.sv2-badge--danger`, `.sv2-badge--primary`, `.sv2-badge--neutral`, `.sv2-badge--info` |
| `_badge.css` | `.sv2-dot`, `.sv2-dot--success`, `.sv2-dot--warning`, `.sv2-dot--danger`, `.sv2-dot--neutral` |
| `_badge.css` | `.sv2-status` (conteneur dot + label) |
| `_collapsible.css` | `.sv2-collapsible`, `.sv2-collapsible__summary`, `.sv2-collapsible__icon`, `.sv2-collapsible__title`, `.sv2-collapsible__subtitle`, `.sv2-collapsible__badge`, `.sv2-collapsible__chevron`, `.sv2-collapsible__content` |
| `_table.css` | `.sv2-table-container`, `.sv2-table`, `.sv2-table__cell--muted`, `.sv2-table__cell--mono`, `.sv2-table__cell--actions` |
| `_table.css` | `.sv2-table-cell-label`, `.sv2-table-cell-label__icon`, `.sv2-table-cell-label__title`, `.sv2-table-cell-label__sub` |
| `_kpi.css` | `.sv2-kpi`, `.sv2-kpi__icon`, `.sv2-kpi__icon--primary`, `.sv2-kpi__icon--success`, `.sv2-kpi__icon--warning`, `.sv2-kpi__icon--danger`, `.sv2-kpi__icon--info`, `.sv2-kpi__icon--neutral` |
| `_form.css` | `.sv2-input`, `.sv2-select`, `.sv2-label`, `.sv2-form-group` |
| `_alert.css` | `.sv2-alert`, `.sv2-alert--success`, `.sv2-alert--warning`, `.sv2-alert--danger`, `.sv2-alert--info` |
| `admin-v2.css` | `.sv2-divider`, `.sv2-flex`, `.sv2-flex-col`, `.sv2-items-center`, `.sv2-justify-between`, `.sv2-gap-sm`, `.sv2-gap-md`, `.sv2-gap-lg`, `.sv2-truncate`, `.sv2-form-inline` (form en inline, pour boutons dans cartes) |
| `admin-v2.css` | `.sv2-text-sm`, `.sv2-text-xs`, `.sv2-text-muted`, `.sv2-text-tertiary`, `.sv2-text-primary`, `.sv2-font-mono`, `.sv2-font-bold` |
| `admin-v2.css` | `.sv2-mt-sm/md/lg/xl`, `.sv2-mb-sm/md/lg/xl` |
| `admin-v2.css` | `[data-sv2-tooltip]` (tooltip CSS-only) |
| `_layout.css` | `.sv2-grid`, `.sv2-grid--2`, `.sv2-grid--3`, `.sv2-grid--4` |
| `_layout.css` | `.sv2-page-header`, `.sv2-page-header__info`, `.sv2-page-header__actions`, `.sv2-page-title`, `.sv2-page-title__icon`, `.sv2-page-subtitle` |

---

## 💎 LOI N°3 — LE GLASSMORPHISM PAR LES VARIABLES

Le Glassmorphisme est codé dans les **design tokens CSS** — pas dans le HTML.

```css
/* Variables qui produisent le verre */
--sv2-glass-bg: rgba(255, 255, 255, 0.75);       /* fond translucide */
--sv2-glass-border: rgba(255, 255, 255, 0.45);   /* bordure lumineuse */
--sv2-glass-backdrop: blur(16px) saturate(180%); /* flou de fond */
```

**`.sv2-card`** applique automatiquement ces variables. Vous n'avez **jamais** besoin
d'écrire `backdrop-filter` ou `rgba()` dans le HTML.

```html
<!-- ✅ Le glassmorphism est automatique -->
<div class="sv2-card">…</div>

<!-- ✅ Variantes sémantiques (teinte de couleur subtile) -->
<div class="sv2-card sv2-card--success">…</div>   <!-- liseré vert -->
<div class="sv2-card sv2-card--warning">…</div>   <!-- liseré orange -->
<div class="sv2-card sv2-card--active">…</div>    <!-- liseré + halo violet -->
```

---

## 🏗️ LOI N°4 — ANATOMIE D'UNE PAGE V2

Chaque page doit respecter cette structure **exacte** :

```twig
{% extends '@Synapse/admin_v2/layout/base.html.twig' %}

{% block v2_page_title %}[Nom Page] | Intelligence | Synapse Admin V2{% endblock %}

{# ── Fil d'Ariane dans la topbar ────────────────────────────────────────── #}
{% block v2_breadcrumb %}
    <span class="sv2-topbar__breadcrumb-sep">›</span>
    <a href="#">[Section]</a>
    <span class="sv2-topbar__breadcrumb-sep">›</span>
    <span class="sv2-topbar__current">[Page]</span>
{% endblock %}

{% block v2_content %}

{# ── En-tête de page ─────────────────────────────────────────────────────── #}
<div class="sv2-page-header">
    <div class="sv2-page-header__info">
        <h1 class="sv2-page-title">
            <span class="sv2-page-title__icon sv2-kpi__icon--primary">
                <i data-lucide="[icon-name]"></i>
            </span>
            [Titre de la page]
        </h1>
        <p class="sv2-page-subtitle">
            [Sous-titre explicatif, max ~120 caractères.]
        </p>
    </div>
    <div class="sv2-page-header__actions">
        {# Boutons d'action (optionnel) #}
    </div>
</div>

{# ── Contenu ─────────────────────────────────────────────────────────────── #}
[… grille ou tableau…]

{% endblock %}
```

### ⚠️ Règles critiques du Page Header
- L'icône du titre utilise **`sv2-page-title__icon sv2-kpi__icon--primary`** (jamais `sv2-badge--*`)
- Le fil d'Ariane est **toujours** dans `{% block v2_breadcrumb %}`, jamais dans `v2_content`
- `sv2-page-header__actions` est omis si aucune action de page n'est nécessaire

---

## 🃏 SQUELETTE — Grille de Cartes

Pour les pages de type catalogue (Fournisseurs, Presets, Personas…) :

**Règle : un seul indicateur d'activité** — Afficher le statut actif uniquement dans le header (badge « Actif »). Ne pas dupliquer dans le footer (pas de « En ligne » / `sv2-status` en plus du badge).

```twig
<div class="sv2-grid sv2-grid--2">
    {% for item in items %}

        {# Variante conditionnelle basée sur l'état métier #}
        {% set variant = item.isActive ? 'sv2-card--active sv2-card--success' : '' %}

        <div class="sv2-card sv2-card--hover {{ variant }}">

            {# ── Header : icône + titre + badges d'état + actions rapides ── #}
            <div class="sv2-card__header">
                <div class="sv2-card__header-icon sv2-kpi__icon--primary">
                    <i data-lucide="[icon]"></i>
                </div>
                <div>
                    <div class="sv2-card__title">{{ item.name }}</div>
                    <div class="sv2-card__subtitle">{{ item.description }}</div>
                </div>
                <div class="sv2-card__header-actions">
                    <span class="sv2-badge sv2-badge--success">
                        <i data-lucide="check-circle-2"></i> Actif
                    </span>
                    <a href="…" class="sv2-btn sv2-btn--ghost sv2-btn--sm sv2-btn--icon"
                       data-sv2-tooltip="Modifier">
                        <i data-lucide="edit-3"></i>
                    </a>
                </div>
            </div>

            {# ── Body Mode Essentiel : toujours visible ─────────────────── #}
            <div class="sv2-card__body sv2-card__body--compact">
                {# Indicateurs de capacités en icônes simples avec tooltip #}
                <div class="sv2-flex sv2-gap-sm sv2-items-center sv2-mb-md">
                    <span class="sv2-text-xs sv2-text-tertiary">Capacités</span>
                    <i data-lucide="brain" class="sv2-text-primary"
                       data-sv2-tooltip="Thinking"></i>
                </div>

                {# ── Mode Avancé : collapsible ──────────────────────────── #}
                <details class="sv2-collapsible">
                    <summary class="sv2-collapsible__summary">
                        <div class="sv2-collapsible__icon">
                            <i data-lucide="settings-2"></i>
                        </div>
                        <div class="sv2-collapsible__title">
                            Paramètres avancés
                            <div class="sv2-collapsible__subtitle">Résumé rapide</div>
                        </div>
                        <i data-lucide="chevron-down" class="sv2-collapsible__chevron"></i>
                    </summary>
                    <div class="sv2-collapsible__content">
                        {# Grille de métriques internes #}
                        <div class="sv2-grid sv2-grid--3">
                            <div>
                                <div class="sv2-text-xs sv2-text-tertiary sv2-font-bold">Label</div>
                                <div class="sv2-font-mono sv2-text-sm sv2-mt-sm">Valeur</div>
                            </div>
                        </div>
                    </div>
                </details>
            </div>

            {# ── Footer : actions principales ────────────────────────────── #}
            {# Règle : un seul indicateur d'activité = badge en header. Footer : pas de "En ligne" en doublon. #}
            <div class="sv2-card__footer">
                <div class="sv2-flex sv2-gap-sm">
                    <a href="…" class="sv2-btn sv2-btn--ghost sv2-btn--sm">…</a>
                    <form action="…" method="POST" class="sv2-form-inline">…</form>
                </div>
                {% if not item.isActive %}
                    <form action="…" method="POST">
                        <input type="hidden" name="token" value="{{ csrf_token(item.id) }}">
                        <button type="submit" class="sv2-btn sv2-btn--outline sv2-btn--sm">
                            <i data-lucide="check-circle-2"></i> Activer
                        </button>
                    </form>
                {% endif %}
            </div>

        </div>
    {% else %}
        {# État vide #}
        <div class="sv2-card">
            <div class="sv2-empty">
                <div class="sv2-empty__icon"><i data-lucide="[icon]"></i></div>
                <div class="sv2-empty__title">Aucun élément trouvé</div>
                <p class="sv2-empty__description">Message explicatif concis.</p>
                <a href="…" class="sv2-btn sv2-btn--primary sv2-mt-md">
                    <i data-lucide="plus"></i> Créer
                </a>
            </div>
        </div>
    {% endfor %}
</div>
```

---

## 📋 SQUELETTE — Tableau de données

Pour les pages de type catalogue technique (Modèles, Logs…) :

```twig
<div class="sv2-card">
    <div class="sv2-table-container">
        <table class="sv2-table">
            <thead>
                <tr>
                    <th>Élément</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                {% for item in items %}
                    <tr>
                        <td>
                            <div class="sv2-table-cell-label">
                                <div class="sv2-table-cell-label__icon sv2-kpi__icon--neutral">
                                    <i data-lucide="cpu"></i>
                                </div>
                                <div>
                                    <div class="sv2-table-cell-label__title">{{ item.name }}</div>
                                    <div class="sv2-table-cell-label__sub sv2-font-mono">{{ item.id }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            {% if item.isEnabled %}
                                <span class="sv2-badge sv2-badge--success">
                                    <i data-lucide="circle-check"></i> Actif
                                </span>
                            {% else %}
                                <span class="sv2-badge sv2-badge--neutral">
                                    <i data-lucide="circle-slash"></i> Inactif
                                </span>
                            {% endif %}
                        </td>
                        <td>
                            <div class="sv2-flex sv2-gap-sm sv2-items-center">
                                <button class="sv2-btn sv2-btn--ghost sv2-btn--sm sv2-btn--icon"
                                        data-sv2-tooltip="Action">
                                    <i data-lucide="settings"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
```

---

## 🎭 LOI N°5 — DUAL-MODE UX

**Toute page d'administration a deux audiences :**

| Mode | Cible | Règle |
|------|-------|-------|
| **Essentiel** | Décideur / Novice | Toujours visible. KPIs, statuts, boutons principaux |
| **Avancé** | Développeur / Ops | Dans `<details class="sv2-collapsible">`. Paramètres techniques |

**La règle d'or :** Si une information nécessite d'être expliquée pour être comprise,
elle va dans le collapsible. Les badges et icônes avec tooltip suffisent pour la section essentielle.

---

## 🔧 LOI N°6 — ICÔNES

**Toutes les icônes utilisent Lucide via `data-lucide`.**

```html
<!-- ✅ Icône Lucide standard -->
<i data-lucide="brain"></i>

<!-- ✅ Icône avec tooltip CSS-only -->
<i data-lucide="brain" class="sv2-text-primary" data-sv2-tooltip="Thinking"></i>

<!-- ✅ Icône dans un conteneur coloré (page title, card header) -->
<span class="sv2-page-title__icon sv2-kpi__icon--primary">
    <i data-lucide="sliders-horizontal"></i>
</span>

<!-- ❌ JAMAIS d'emoji à la place d'une icône -->
<!-- ❌ JAMAIS d'icône Font Awesome, Material, etc. -->
```

### Palette d'icônes de référence par domaine

| Domaine | Icône principale | Icônes secondaires |
|---------|-----------------|-------------------|
| Intelligence | `brain` | `plug`, `cpu`, `sliders-horizontal`, `zap`, `cloud` |
| Presets | `sliders-horizontal` | `check-circle-2`, `copy`, `flask-conical`, `edit-3` |
| Conversation | `message-circle` | `wrench`, `clock`, `settings` |
| Mémoire | `sparkles` | `database-zap`, `file-text`, `shield` |
| Usage | `bar-chart-3` | `zap`, `dollar-sign`, `trending-up` |
| Sécurité | `shield` | `key-round`, `user-check`, `eye-off` |
| Système | `settings` | `activity`, `bug`, `info` |

---

## ⚡ LOI N°7 — ROUTES ET CSRF

**Règle de nommage des routes :**
- Routes V2 natives : `synapse_v2_admin_[section]_[action]`
- Liens vers V1 (actions complexes non encore migrées) : routes V1 existantes

**Pour les formulaires POST, toujours inclure le token CSRF :**
```twig
<form action="{{ path('synapse_v2_admin_[action]', {id: item.id}) }}" method="POST">
    <input type="hidden" name="token" value="{{ csrf_token(item.id) }}">
    <button type="submit" class="sv2-btn sv2-btn--primary sv2-btn--sm">
        <i data-lucide="check"></i> Valider
    </button>
</form>
```

---

## 🗺️ ORDRE DE MIGRATION

Suivre cette séquence pour garder la cohérence de l'expérience :

```
✅ Infrastructure (layout, sidebar, topbar, design system)
✅ Tableau de bord (dashboard)
✅ Intelligence > Fournisseurs, Modèles, Presets
✅ Conversation > Paramètres, Outils, Historique
✅ Mémoire > Embeddings
✅ Usage > Analytics
✅ Système > Debug LLM, Santé, À propos
✅ Sécurité > Audit, Clés API, RGPD
🔜 Nouvelles fonct. > Personas, Documents, Souvenirs, Quotas, Export
```

---

## 📁 STRUCTURE DES FICHIERS

```
src/AdminV2/Controller/
├── Intelligence/
│   ├── ProviderController.php     ✅
│   ├── ModelController.php        ✅
│   └── PresetController.php       ✅
├── Conversation/
│   ├── SettingsController.php     ✅
│   ├── ToolsController.php        ✅
│   └── HistoryController.php      ✅
├── Memoire/
│   └── EmbeddingController.php    ✅
├── Usage/
│   └── AnalyticsController.php    ✅
├── Systeme/
│   ├── DebugController.php        ✅
│   ├── HealthController.php       ✅
│   └── AboutController.php        ✅
├── Securite/
│   ├── AuditController.php        ✅
│   ├── ApiKeysController.php      ✅
│   └── GdprController.php         ✅
└── PlaceholderController.php      # Personas, Documents, Souvenirs, Quotas, Export

src/Infrastructure/Resources/views/admin_v2/
├── layout/       base.html.twig, _sidebar, _topbar
├── dashboard/    index.html.twig       🌟 KPIs
├── intelligence/ providers, models, presets
├── conversation/ settings, tools, history
├── memoire/      embeddings
├── usage/        analytics
├── systeme/      debug, health, about
└── securite/     audit, api_keys, gdpr
```

---

## 🌟 TEMPLATES DE RÉFÉRENCE

Pour chaque pattern, utilisez ces fichiers comme modèle canonique :

| Pattern | Fichier de référence |
|---------|---------------------|
| Page avec grille de cartes + Dual-Mode | `intelligence/presets.html.twig` |
| Page avec tableau de données + actions inline | `intelligence/models.html.twig` |
| Page dashboard avec KPIs | `dashboard/index.html.twig` |
| Page formulaire multi-cartes (édition config) | `conversation/settings.html.twig` |
| Page tableau readonly + KPIs | `conversation/tools.html.twig` |
| Page formulaire + panel test JS | `memoire/embeddings.html.twig` |
| Page stats avec sélecteur de période | `usage/analytics.html.twig` |
| Page checks sémantiques (ok/warning/error) | `systeme/health.html.twig` |
| Page tableau paginé Break-Glass + audit | `conversation/history.html.twig` |
| Page dashboard RGPD + checklist | `securite/gdpr.html.twig` |
| Page tableau secrets masqués | `securite/api_keys.html.twig` |

---

*Rédigé par Antigravity (Google DeepMind) — Version 2026-02-26*
*Ce document est vivant : mettez-le à jour à chaque nouveau pattern établi.*
