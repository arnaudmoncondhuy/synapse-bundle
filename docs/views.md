# Intégration des vues

Ce document couvre l'intégration des templates Twig du bundle et leur personnalisation.

## Vue d'ensemble

Le bundle fournit deux modes de présentation :

1. **Standalone** — Interface complète et autonome (avec sidebar, historique)
2. **Module** — Intégration dans votre design system existant

## Modes de layout

### Mode `standalone`

Utilisé quand le bundle fonctionne de manière autonome, avec sa propre interface.

```twig
{# templates/page.html.twig #}
{% extends '@Synapse/chat/page.html.twig' %}
```

Ou directement :
```twig
{{ include('@Synapse/chat/component.html.twig') }}
```

### Mode `module`

Utilisé quand le bundle s'intègre dans une application existante avec un design system commun.

**Prérequis** : Un template parent `templates/core/module_base.html.twig` dans votre projet.

Votre projet fournit :
```twig
{# templates/core/module_base.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}{% endblock %}</title>
</head>
<body>
    <header>
        <span>{% block header_icon %}{% endblock %}</span>
        <h1>{% block header_title %}{% endblock %}</h1>
        <p>{% block header_subtitle %}{% endblock %}</p>
    </header>

    <main>
        {% block module_content %}{% endblock %}
    </main>
</body>
</html>
```

Le bundle utilise ce layout :
```yaml
# config/packages/synapse.yaml
synapse:
    ui:
        layout_mode: 'module'
```

---

## Widget de chat : `@Synapse/chat/component.html.twig`

Composant de chat standalone, design Google Gemini, entièrement autonome.

### Variables disponibles

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `history` | array | `[]` | Historique messages `[{role: 'user'\|'assistant', content: string}, ...]` |
| `controller_override` | string | `'synapse--chat'` | Nom du controller Stimulus (normalement, ne pas modifier) |
| `attr` | array | `{}` | Attributs HTML sur le conteneur (ex: `data-room-id`) |
| `allow_new_chat` | bool | `false` | Afficher bouton "Nouvelle conversation" |

### Blocks surchargeables

| Block | Description |
|---|---|
| `synapse_header` | Zone d'en-tête au-dessus des messages (vide par défaut) |
| `synapse_greeting_content` | Contenu de l'état vide ("Bonjour, comment puis-je...") |

### Utilisation

```twig
{# Intégration simple #}
{{ include('@Synapse/chat/component.html.twig') }}

{# Avec historique et options #}
{{ include('@Synapse/chat/component.html.twig', {
    'history': messages,
    'allow_new_chat': true,
    'attr': {'data-conversation-id': conversation.id}
}) }}

{# Avec personnalisation du greeting #}
{% include '@Synapse/chat/component.html.twig' %}
    {% block synapse_greeting_content %}
        <h2>Bienvenue sur mon appli</h2>
        <p>Pose-moi une question !</p>
    {% endblock %}
{% endinclude %}
```

---

## Page de chat complète : `@Synapse/chat/page.html.twig`

Page HTML5 complète avec sidebar + composant. Aucun `extends` requis.

### Blocks surchargeables

| Block | Description |
|---|---|
| `chat_title` | Balise `<title>` |
| `chat_stylesheets` | Liens CSS (inclut `synapse.css` + `sidebar.css`) |
| `chat_importmap` | Balise `{{ importmap('app') }}` |
| `chat_body` | Conteneur principal (sidebar + composant) |
| `chat_scripts` | Scripts fin de `</body>` |

### Surcharge par le projet

Créer :
```twig
{# templates/bundles/SynapseBundle/chat/page.html.twig #}
{% extends '@!Synapse/chat/page.html.twig' %}

{% block chat_title %}Mon App Chat{% endblock %}

{% block chat_body %}
    {{ parent() }}
    <!-- Contenu additionnel après sidebar + chat -->
{% endblock %}
```

---

## Sidebar : `@Synapse/chat/sidebar.html.twig`

Historique des conversations, chargé dynamiquement.

### Variables attendues

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `apiUrl` | string | `'/synapse/api/conversations'` | Endpoint API conversations |
| `currentConversationId` | string | `''` | ID de la conversation active |
| `position` | string | `'left'` | Position : `'left'` ou `'right'` |

**Normalement, ce template est utilisé automatiquement via le controller Stimulus `sidebar`. Pas de surcharge manuelle requise.**

---

## Layouts Admin

### Mode Standalone : `@Synapse/admin/layout.html.twig`

Layout complet autonome pour l'admin. Utiliser quand le bundle est indépendant.

### Blocks surchargeables (11 blocks)

| Block | Description | Contenu par défaut |
|---|---|---|
| `admin_meta` | Balises `<meta>` du `<head>` | charset + viewport |
| `admin_title` | Titre `<title>` | "Synapse Admin" |
| `admin_stylesheets` | Liens `<link>` CSS | 3 fichiers CSS du bundle |
| `admin_custom_styles` | CSS custom du projet | Vide |
| `admin_head_scripts` | Scripts dans `<head>` | Lucide Icons CDN |
| `admin_sidebar` | Sidebar complète avec nav | Sidebar navigationn + branding |
| `admin_branding` | Logo + nom du bundle | Icône + "Synapse" |
| `admin_navigation` | Menu principal | Liens vers toutes les sections |
| `admin_sidebar_footer` | Footer de la sidebar | Version + crédit |
| `admin_flash_messages` | Messages flash (success/error) | Alertes stylisées |
| `admin_content` | **Contenu principal** (à surcharger) | Vide |
| `admin_body_scripts` | Scripts fin `</body>` | `lucide.createIcons()` |

### Exemple : surcharge complète

```twig
{# templates/bundles/SynapseBundle/admin/layout.html.twig #}
{% extends '@!Synapse/admin/layout.html.twig' %}

{% block admin_title %}Ma Super App — Synapse Admin{% endblock %}

{% block admin_branding %}
    <div class="synapse-admin__brand">
        <img src="{{ asset('images/my-logo.png') }}" alt="Logo">
        <span>Mon App</span>
    </div>
{% endblock %}

{% block admin_custom_styles %}
    <style>
        :root {
            --custom-synapse-primary: #e63946;
            --custom-synapse-bg-sidebar: #2b2d42;
        }
    </style>
{% endblock %}

{% block admin_content %}
    <h1>Mon contenu custom</h1>
    <p>Contenu spécifique à mon admin...</p>
{% endblock %}
```

### Mode Module : `@Synapse/admin/layout_module.html.twig`

Intégration dans un design system existant. Étend `core/module_base.html.twig` du projet.

### Blocks surchargeables (7 blocks)

| Block | Description | Défaut |
|---|---|---|
| `admin_header_icon` | Icône Lucide du header | `'brain-circuit'` |
| `admin_header_color` | Couleur hex du header | `'#8b5cf6'` |
| `admin_header_title` | Titre du module | `'Synapse'` |
| `admin_header_subtitle` | Sous-titre | `'Administration de l\'assistant IA'` |
| `admin_header_breadcrumbs` | Variable `header_breadcrumbs` | `[{label: 'Accueil'}, {label: 'Synapse'}]` |
| `admin_header_actions` | Boutons dans le header | Vide |
| `admin_content` | **Contenu principal** (à surcharger) | Vide |

### Exemple : intégration module

```twig
{# templates/bundles/SynapseBundle/admin/layout_module.html.twig #}
{% extends '@!Synapse/admin/layout_module.html.twig' %}

{% block admin_header_color %}#ff6b6b{% endblock %}
{% block admin_header_icon %}cpu{% endblock %}

{% block admin_content %}
    <!-- Contenu qui s'intègre au module_base.html.twig -->
    <div class="synapse-admin-content">
        {% block synapse_custom %}{% endblock %}
    </div>
{% endblock %}
```

---

## Pages admin fournies

Le bundle fournit plusieurs pages pré-intégrées :

| Page | Template | Description |
|---|---|---|
| Dashboard | `@Synapse/admin/dashboard.html.twig` | KPIs conversations, users, tokens |
| Analytics | `@Synapse/admin/analytics.html.twig` | Graphiques, usage par modèle |
| Providers | `@Synapse/admin/providers.html.twig` | Gestion Gemini/OVH + test |
| Edit Provider | `@Synapse/admin/provider_edit.html.twig` | Formulaire edit provider |
| Presets | `@Synapse/admin/presets.html.twig` | Gestion des presets LLM |
| Edit Preset | `@Synapse/admin/preset_edit.html.twig` | Formulaire edit preset |
| Test Preset | `@Synapse/admin/preset_test_result.html.twig` | Résultat test preset |
| Models | `@Synapse/admin/models.html.twig` | Catalogue modèles + pricing |
| Settings | `@Synapse/admin/settings.html.twig` | Paramètres globaux |
| Debug Logs | `@Synapse/admin/debug_logs.html.twig` | Liste logs debug |
| Conversation | `@Synapse/admin/conversation.html.twig` | Vue d'une conversation |

Toutes ces pages étendent soit `layout.html.twig` (standalone) soit `layout_module.html.twig` (module).

---

## Surcharge de templates par le projet

Symfony permet de surcharger les templates du bundle via une convention de dossier.

### Convention de chemin

```
templates/bundles/SynapseBundle/[chemin-relatif]
```

### Exemples

```
# Surcharger le composant chat
templates/bundles/SynapseBundle/chat/component.html.twig

# Surcharger la page de chat
templates/bundles/SynapseBundle/chat/page.html.twig

# Surcharger le layout admin
templates/bundles/SynapseBundle/admin/layout.html.twig

# Surcharger le dashboard
templates/bundles/SynapseBundle/admin/dashboard.html.twig
```

### Exemple concret

```twig
{# templates/bundles/SynapseBundle/chat/component.html.twig #}
{% extends '@!Synapse/chat/component.html.twig' %}

{% block synapse_greeting_content %}
    <div class="my-custom-greeting">
        <h2>Bienvenue sur notre assistant IA</h2>
        <p>Posez-moi vos questions sur nos services.</p>
        <ul>
            <li><button onclick="askQuestion('Tarifs ?')">Tarifs</button></li>
            <li><button onclick="askQuestion('Comment m\'inscrire ?')">Inscription</button></li>
        </ul>
    </div>
{% endblock %}
```

---

## Personnalisation CSS

### Variables admin

Le thème admin est contrôlable via variables CSS avec préfixe `--custom-synapse-*`.

```css
/* assets/styles/custom.css */
:root {
    /* Couleurs primaires */
    --custom-synapse-primary: #ff6b6b;           /* Rose/rouge */
    --custom-synapse-primary-dark: #ee5a52;      /* Plus foncé */
    --custom-synapse-primary-light: #ffe0e0;     /* Plus clair */
    --custom-synapse-primary-fade: #fff5f5;      /* Très clair */

    /* Couleurs sémantiques */
    --custom-synapse-success: #51cf66;           /* Vert */
    --custom-synapse-warning: #ffd43b;           /* Jaune */
    --custom-synapse-error: #ff6b6b;             /* Rouge */

    /* Fonds */
    --custom-synapse-bg-main: #f8fafc;           /* Fond principal */
    --custom-synapse-bg-surface: #ffffff;        /* Surface */
    --custom-synapse-bg-sidebar: #0f172a;        /* Sidebar sombre */

    /* Texte */
    --custom-synapse-text-main: #1e293b;         /* Texte principal */
    --custom-synapse-text-muted: #64748b;        /* Texte grisé */
    --custom-synapse-text-light: #94a3b8;        /* Texte léger */
    --custom-synapse-text-inverse: #ffffff;      /* Texte sur fond sombre */

    /* Bordures */
    --custom-synapse-border: #e2e8f0;            /* Couleur bordure */

    /* Arrondis */
    --custom-synapse-radius-sm: 0.375rem;        /* Petit rayon */
    --custom-synapse-radius: 0.75rem;            /* Rayon normal */
    --custom-synapse-radius-lg: 1rem;            /* Grand rayon */

    /* Ombres */
    --custom-synapse-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
    --custom-synapse-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    --custom-synapse-shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);

    /* Typo */
    --custom-synapse-font-sans: 'Inter', system-ui, sans-serif;
}
```

### Variables chat

Le composant chat utilise ses propres variables :

```css
:root {
    --synapse-bg-primary: #ffffff;               /* Fond message utilisateur */
    --synapse-bg-surface: #f0f4f9;               /* Fond zone input */
    --synapse-text-primary: #1f1f1f;             /* Texte principal */
    --synapse-text-muted: #444746;               /* Texte grisé */
    --synapse-primary-color: #0b57d0;            /* Couleur spinner/accent */
    --synapse-input-bg: #f0f4f9;                 /* Fond input */
    --synapse-radius-input: 24px;                /* Arrondi input */
    --synapse-radius-bubble: 20px 20px 4px 20px; /* Arrondi bulles messages */
    --synapse-max-width: 900px;                  /* Largeur max */
}
```

### Exemple : theme sombre personnalisé

```css
:root {
    /* Admin sombre */
    --custom-synapse-primary: #5e22fc;
    --custom-synapse-bg-main: #0f0f1e;
    --custom-synapse-bg-surface: #1a1a2e;
    --custom-synapse-bg-sidebar: #0a0a14;
    --custom-synapse-text-main: #f0f0ff;
    --custom-synapse-border: #2a2a3e;

    /* Chat sombre */
    --synapse-bg-primary: #1a1a2e;
    --synapse-bg-surface: #2a2a3e;
    --synapse-text-primary: #f0f0ff;
    --synapse-primary-color: #7c3aed;
}
```

---

## Fonctions Twig

Le bundle expose plusieurs fonctions Twig.

### `synapse_admin_layout()`

Résout dynamiquement le layout à utiliser (standalone ou module selon la config).

```twig
{% if synapse_admin_layout() == 'module' %}
    {# Mode module #}
{% else %}
    {# Mode standalone #}
{% endif %}
```

### `synapse_get_personas()`

Retourne la liste des personas disponibles.

```twig
{% set personas = synapse_get_personas() %}

<select name="persona">
    {% for key, persona in personas %}
        <option value="{{ key }}">
            {{ persona.emoji }} {{ persona.name }}
        </option>
    {% endfor %}
</select>
```

### `synapse_config()`

Retourne le preset actif (entité `SynapsePreset`).

```twig
{% set preset = synapse_config() %}

<p>Model: {{ preset.model }}</p>
<p>Temperature: {{ preset.generationTemperature }}</p>
```

### `synapse_version()`

Retourne la version du bundle.

```twig
<footer>Powered by SynapseBundle v{{ synapse_version() }}</footer>
```

---

## Filtres Twig

### `synapse_markdown`

Convertit le Markdown en HTML. Supporte :
- **Gras** (`**texte**`)
- *Italique* (`*texte*`)
- Listes
- Blocs de code
- Groupes de boutons (syntaxe custom)
- Chiffrement automatique (décrypte si texte chiffré)

```twig
{# Convertir la réponse LLM en HTML #}
{{ message.content|synapse_markdown }}
```

Résultat :
```html
<p><strong>Texte en gras</strong> et <em>italique</em></p>
<code>du code</code>
```

---

## Voir aussi

- [Configuration](configuration.md) — Options `synapse.yaml`
- [Usage](usage.md) — ChatService, outils IA, events
- [Changelog](changelog.md) — Historique des versions
