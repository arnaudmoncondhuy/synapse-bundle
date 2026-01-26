# Gestion des Assets & Personnalisation

Le bundle utilise **Symfony UX** et **Stimulus** pour gérer l'interactivité.
Le style est conçu pour être moderne ("Gemini-like") mais entièrement surchargeable.

## 1. Javascript (Stimulus)

Le contrôleur Stimulus est enregistré automatiquement sous le nom `synapse--chat`.
Vous n'avez **PAS** besoin de l'importer manuellement dans votre `app.js`.

### Configuration
Les textes et comportements peuvent être configurés via des attributs sur le conteneur HTML (dans votre template de surcharge) :

```twig
<div data-controller="synapse--chat"
     data-synapse--chat-welcome-message-value="Bienvenue !"
     data-synapse--chat-debug-value="true">
    ...
</div>
```

## 2. CSS (Thème)

Le style est inclus via `synapse.css`. Pour le surcharger, vous pouvez redéfinir les variables CSS dans votre propre feuille de style :

```css
:root {
    /* Couleurs Principales */
    --synapse-bg-primary: #ffffff;
    --synapse-primary-color: #0b57d0;
    
    /* Bulles */
    --synapse-radius-bubble: 12px;
}
```

## 3. Surcharge du Template

Le template `component.html.twig` définit des **Blocks Twig** pour vous permettre d'injecter votre propre contenu :

```twig
{# templates/chat.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <div class="assistant-layout">
        {# On inclut le composant en surchargeant le greeting #}
        {% embed '@Synapse/chat/component.html.twig' %}
            
            {% block synapse_greeting_content %}
                <div class="greeting-title">
                    <span>Bonjour {{ app.user.firstName }} !</span>
                </div>
            {% endblock %}

        {% endembed %}
    </div>
{% endblock %}
```
