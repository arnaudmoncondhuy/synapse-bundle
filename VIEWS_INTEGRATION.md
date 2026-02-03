# Synapse Bundle - Guide d'Intégration des Vues

## 🎨 Vue d'ensemble

Le bundle Synapse propose **deux modes d'utilisation** pour ses interfaces admin :

1. **Mode Standalone** : Interface complète avec sidebar et navigation
2. **Mode Module** : Intégration dans un système de modules existant

---

## 📦 Mode 1 : Standalone (Par Défaut)

### Configuration

```yaml
# config/packages/synapse.yaml
synapse:
    ui:
        layout_mode: 'standalone'  # Valeur par défaut
```

### Utilisation Plug-and-Play

Les templates admin utilisent automatiquement le layout configuré :

```twig
{# Votre controller renvoie vers une vue admin #}
{% extends synapse_admin_layout() %}  {# Résout automatiquement vers layout.html.twig #}

{% block admin_content %}
    <h1>Mon contenu</h1>
{% endblock %}
```

**Note** : `synapse_admin_layout()` est une fonction Twig qui retourne le bon layout selon la configuration.

### Personnalisation via Blocks

Tous les templates proposent des blocks surchargeables :

```twig
{% extends '@Synapse/admin/layout.html.twig' %}

{# Changer le branding #}
{% block admin_branding %}
    <div class="synapse-admin__brand">
        <img src="/mon-logo.png" alt="Mon App">
    </div>
{% endblock %}

{# Ajouter du CSS custom #}
{% block admin_custom_styles %}
    <link rel="stylesheet" href="{{ asset('my-custom-admin.css') }}">
{% endblock %}

{# Contenu principal #}
{% block admin_content %}
    <div class="synapse-admin__card">
        Mon contenu personnalisé
    </div>
{% endblock %}
```

### Blocks Disponibles

| Block | Description |
|-------|-------------|
| `admin_meta` | Balises `<meta>` HTML |
| `admin_title` | Titre de la page |
| `admin_stylesheets` | Inclusion CSS (contient déjà les CSS du bundle) |
| `admin_custom_styles` | Vos styles custom |
| `admin_head_scripts` | Scripts dans le `<head>` |
| `admin_sidebar` | Sidebar complète |
| `admin_branding` | Logo/nom dans la sidebar |
| `admin_navigation` | Menu de navigation |
| `admin_sidebar_footer` | Footer de la sidebar |
| `admin_flash_messages` | Messages flash |
| `admin_content` | **Contenu principal (à surcharger)** |
| `admin_body_scripts` | Scripts en fin de `<body>` |

---

## 🔌 Mode 2 : Intégration Module

### Pour les projets avec `module_base.html.twig`

Si votre projet utilise déjà un système de modules (comme l'Intranet), configurez le mode module :

```yaml
# config/packages/synapse.yaml
synapse:
    ui:
        layout_mode: 'module'  # Active le mode intégration
```

Ensuite, vos templates utilisent automatiquement `layout_module.html.twig` :

```twig
{# Votre template admin #}
{% extends synapse_admin_layout() %}  {# Résout automatiquement vers layout_module.html.twig #}

{% block admin_content %}
    <div class="card">
        Mon contenu qui s'intègre dans le module_base.html.twig
    </div>
{% endblock %}
```

**Important** : `layout_module.html.twig` extend `core/module_base.html.twig`. Si votre projet utilise un nom différent, vous devrez créer votre propre adapter (voir section Troubleshooting).

### Personnalisation du Header Module

```twig
{% extends '@Synapse/admin/layout_module.html.twig' %}

{# Personnaliser l'icône #}
{% block admin_header_icon %}shield-check{% endblock %}

{# Personnaliser la couleur #}
{% block admin_header_color %}#ff6b6b{% endblock %}

{# Personnaliser le titre #}
{% block admin_header_title %}Mon Module IA{% endblock %}

{# Ajouter des actions dans le header #}
{% block admin_header_actions %}
    <a href="{{ path('export') }}" class="btn btn-primary">
        <i class="bi bi-download"></i> Exporter
    </a>
{% endblock %}

{% block admin_content %}
    {# Votre contenu #}
{% endblock %}
```

### Blocks Disponibles (Mode Module)

| Block | Description |
|-------|-------------|
| `admin_header_icon` | Icône Lucide (défaut: `brain-circuit`) |
| `admin_header_color` | Couleur hexa (défaut: `#8b5cf6`) |
| `admin_header_title` | Titre du module (défaut: `Synapse`) |
| `admin_header_subtitle` | Sous-titre (défaut: `Administration de l'assistant IA`) |
| `admin_header_breadcrumbs` | Fil d'ariane personnalisé |
| `admin_header_actions` | Boutons d'action dans le header |
| `admin_content` | **Contenu principal (à surcharger)** |

---

## 🎨 Personnalisation CSS

### Variables CSS Overridables

Créez un fichier CSS dans votre projet pour override les variables :

```css
/* assets/styles/synapse-custom.css */
:root {
    /* Changer la couleur primaire */
    --custom-synapse-primary: #ff6b6b;
    --custom-synapse-primary-dark: #ee5a52;
    
    /* Changer les radius */
    --custom-synapse-radius: 0.5rem;
    
    /* Changer la sidebar */
    --custom-synapse-bg-sidebar: #1a1a2e;
}
```

Puis incluez-le dans votre template :

```twig
{% extends '@Synapse/admin/layout.html.twig' %}

{% block admin_custom_styles %}
    <link rel="stylesheet" href="{{ asset('styles/synapse-custom.css') }}">
{% endblock %}
```

### Classes CSS Disponibles

Toutes les classes utilisent le namespace `synapse-admin__` pour éviter les conflits :

```html
<!-- Layout -->
<div class="synapse-admin__layout">
    <aside class="synapse-admin__sidebar">...</aside>
    <main class="synapse-admin__main">...</main>
</div>

<!-- Components -->
<div class="synapse-admin__card">
    <div class="synapse-admin__card-header">...</div>
    <div class="synapse-admin__card-body">...</div>
</div>

<!-- Buttons -->
<button class="synapse-admin__btn synapse-admin__btn--primary">Primaire</button>
<button class="synapse-admin__btn synapse-admin__btn--secondary">Secondaire</button>

<!-- Badges -->
<span class="synapse-admin__badge synapse-admin__badge--success">Actif</span>
<span class="synapse-admin__badge synapse-admin__badge--warning">Attention</span>
<span class="synapse-admin__badge synapse-admin__badge--error">Erreur</span>

<!-- Forms -->
<div class="synapse-admin__form-group">
    <label class="synapse-admin__label">Label</label>
    <input class="synapse-admin__input" type="text">
    <p class="synapse-admin__help">Texte d'aide</p>
</div>

<!-- Grid -->
<div class="synapse-admin__grid-2">...</div> <!-- 2 colonnes -->
<div class="synapse-admin__grid-3">...</div> <!-- 3 colonnes -->
<div class="synapse-admin__grid-4">...</div> <!-- 4 colonnes -->

<!-- Tables -->
<div class="synapse-admin__table-container">
    <table class="synapse-admin__table">...</table>
</div>

<!-- Alerts -->
<div class="synapse-admin__alert synapse-admin__alert--success">...</div>
<div class="synapse-admin__alert synapse-admin__alert--error">...</div>
<div class="synapse-admin__alert synapse-admin__alert--info">...</div>

<!-- KPI Components -->
<div class="synapse-admin__kpi">
    <div class="synapse-admin__kpi-icon synapse-admin__kpi-icon--primary">
        <i data-lucide="activity"></i>
    </div>
    <div>
        <div class="synapse-admin__kpi-label">Label</div>
        <div class="synapse-admin__kpi-value">42</div>
        <div class="synapse-admin__kpi-sub">Sous-texte</div>
    </div>
</div>
```

---

## 💬 Interface Chat

### Utilisation Basique

```twig
{{ include('@Synapse/chat/component.html.twig') }}
```

### Avec Historique

```twig
{{ include('@Synapse/chat/component.html.twig', {
    'history': [
        {'role': 'user', 'content': 'Bonjour'},
        {'role': 'assistant', 'content': 'Bonjour ! Comment puis-je vous aider ?'}
    ]
}) }}
```

### Controller Custom

```twig
{{ include('@Synapse/chat/component.html.twig', {
    'controller_override': 'myapp--custom-chat',
    'attr': {
        'data-conversation-id': conversation.id,
        'data-user-id': app.user.id
    }
}) }}
```

### Variables Disponibles

| Variable | Type | Défaut | Description |
|----------|------|--------|-------------|
| `history` | array | `[]` | Historique des messages `[{role, content}]` |
| `controller_override` | string | `'synapse--chat'` | Nom du controller Stimulus custom |
| `attr` | array | `{}` | Attributs HTML supplémentaires |
| `allow_new_chat` | bool | `false` | Afficher le bouton "Nouvelle conversation" |

---

## 🚀 Exemples Complets

### Exemple 1 : Page Admin Standalone Custom

```twig
{% extends '@Synapse/admin/layout.html.twig' %}

{% block admin_title %}Ma Page Custom - Admin{% endblock %}

{# Branding personnalisé #}
{% block admin_branding %}
    <div class="synapse-admin__brand">
        <div class="synapse-admin__brand-icon" style="background: #ff6b6b;">
            <i data-lucide="zap"></i>
        </div>
        Mon App
    </div>
{% endblock %}

{# CSS custom #}
{% block admin_custom_styles %}
    <style>
        :root {
            --custom-synapse-primary: #ff6b6b;
        }
    </style>
{% endblock %}

{# Contenu #}
{% block admin_content %}
    <header class="synapse-admin__header">
        <div>
            <h1 class="synapse-admin__title">Ma Page Custom</h1>
            <p class="synapse-admin__subtitle">Description de ma page</p>
        </div>
    </header>

    <div class="synapse-admin__grid-3">
        <div class="synapse-admin__card">
            <div class="synapse-admin__card-body">
                Contenu 1
            </div>
        </div>
        <div class="synapse-admin__card">
            <div class="synapse-admin__card-body">
                Contenu 2
            </div>
        </div>
        <div class="synapse-admin__card">
            <div class="synapse-admin__card-body">
                Contenu 3
            </div>
        </div>
    </div>
{% endblock %}
```

### Exemple 2 : Intégration Module avec Actions

```twig
{% extends '@Synapse/admin/layout_module.html.twig' %}

{% block admin_header_icon %}database{% endblock %}
{% block admin_header_color %}#3b82f6{% endblock %}
{% block admin_header_title %}Gestion des Données{% endblock %}

{% block admin_header_actions %}
    <a href="{{ path('export_data') }}" class="btn btn-outline-primary">
        <i class="bi bi-download"></i> Exporter
    </a>
    <a href="{{ path('import_data') }}" class="btn btn-primary">
        <i class="bi bi-upload"></i> Importer
    </a>
{% endblock %}

{% block admin_content %}
    <div class="card">
        <div class="card-body">
            {# Utilise les classes Bootstrap de votre projet #}
            <table class="table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {% for item in items %}
                        <tr>
                            <td>{{ item.name }}</td>
                            <td>{{ item.date|date('d/m/Y') }}</td>
                            <td>
                                <a href="{{ path('edit', {id: item.id}) }}" class="btn btn-sm btn-primary">
                                    Modifier
                                </a>
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    </div>
{% endblock %}
```

---

## 🔧 Troubleshooting

### Les styles ne s'appliquent pas

Vérifiez que les CSS sont bien chargés dans votre `importmap.php` :

```php
// importmap.php
return [
    // ...
    'synapse/styles/admin/synapse-variables.css' => [
        'path' => '@synapse-bundle/styles/admin/synapse-variables.css',
    ],
    'synapse/styles/admin/synapse-admin.css' => [
        'path' => '@synapse-bundle/styles/admin/synapse-admin.css',
    ],
];
```

### Conflit avec Bootstrap/Tailwind

Le namespace `synapse-admin__` évite les conflits. Si vous avez quand même des problèmes :

1. **Mode Module** : Les styles du projet prennent le dessus
2. **Mode Standalone** : Ajoutez vos overrides dans `admin_custom_styles`

### Les icônes Lucide ne s'affichent pas

Le script Lucide est chargé depuis CDN par défaut. Pour utiliser une version locale :

```twig
{% extends '@Synapse/admin/layout.html.twig' %}

{% block admin_head_scripts %}
    <script src="{{ asset('lucide.min.js') }}"></script>
{% endblock %}
```

---

## 📚 Ressources

- [Documentation Lucide Icons](https://lucide.dev/)
- [AssetMapper Symfony](https://symfony.com/doc/current/frontend/asset_mapper.html)
- [Twig Blocks](https://twig.symfony.com/doc/3.x/tags/block.html)
