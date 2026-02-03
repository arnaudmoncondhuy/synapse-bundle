# SynapseBundle

Un bundle Symfony réutilisable pour l'intégration d'assistants IA avec Google Gemini.

## ✨ Fonctionnalités

- 🤖 **Intégration Vertex AI** : Support complet de Google Gemini (2.0+)
- 🔧 **Function Calling** : Système extensible d'outils IA
- 📡 **Streaming NDJSON** : Réponses en temps réel
- 💾 **Persistance** : Historique des conversations en base de données
- 🔒 **Sécurité** : Chiffrement server-side (Sodium), filtres de contenu
- 🎨 **UI Moderne** : Templates Twig prêts à l'emploi (design Gemini)
- 🧩 **Dual-Mode** : Standalone ou intégration dans modules existants
- 🎯 **Thinking Mode** : Support du raisonnement Chain-of-Thought
- 💰 **Context Caching** : Optimisation des coûts (~90% d'économie)

## 📋 Prérequis

- PHP 8.4+
- Symfony 7.0+
- Compte Google Cloud avec Vertex AI activé

## 🚀 Installation

```bash
composer require arnaudmoncondhuy/synapse-bundle
```

## ⚙️ Configuration

### Configuration Minimale

```yaml
# config/packages/synapse.yaml
synapse:
    vertex:
        project_id: '%env(VERTEX_PROJECT_ID)%'
        region: 'europe-west1'
    model: 'gemini-2.5-flash'
```

### Configuration Complète (Optionnelle)

```yaml
synapse:
    vertex:
        project_id: '%env(VERTEX_PROJECT_ID)%'
        region: 'europe-west1'
    
    model: 'gemini-2.5-flash'
    
    # Prompt système personnalisé
    system_prompt: |
        Tu es un assistant IA serviable et précis.
        Date actuelle: {DATE}
        Utilisateur: {PRENOM} {NOM} ({EMAIL})
    
    # Filtres de sécurité
    safety:
        enabled: true
        default_threshold: 'BLOCK_MEDIUM_AND_ABOVE'
        hate_speech: 'BLOCK_MEDIUM_AND_ABOVE'
        dangerous_content: 'BLOCK_MEDIUM_AND_ABOVE'
        harassment: 'BLOCK_MEDIUM_AND_ABOVE'
        sexually_explicit: 'BLOCK_ONLY_HIGH'
    
    # Paramètres de génération
    generation:
        temperature: 1.0
        top_p: 0.95
        top_k: 40
        max_output_tokens: 8192
    
    # Thinking Mode (Gemini 2.0+)
    thinking:
        enabled: true
        budget: 8192
    
    # Context Caching
    context_caching:
        enabled: true
    
    # Rétention des données
    retention_days: 90
    
    # Détection des risques
    risk_detection_enabled: true
```

## 📖 Usage

### 1. Interface Chat (Plug-and-Play)

```twig
{# templates/chat/index.html.twig #}
{{ include('@Synapse/chat/component.html.twig') }}
```

### 2. Avec Historique

```twig
{{ include('@Synapse/chat/component.html.twig', {
    'history': conversation.messages
}) }}
```

### 3. Interface Admin

#### Mode Standalone

```twig
{% extends '@Synapse/admin/layout.html.twig' %}

{% block admin_content %}
    <h1>Mon contenu admin</h1>
{% endblock %}
```

#### Mode Intégration Module

```twig
{% extends '@Synapse/admin/layout_module.html.twig' %}

{% block admin_header_icon %}shield-check{% endblock %}
{% block admin_header_color %}#ff6b6b{% endblock %}

{% block admin_content %}
    {# Votre contenu qui s'intègre dans module_base.html.twig #}
{% endblock %}
```

### 4. Créer des Outils (Tools)

Les outils sont automatiquement détectés via l'interface `AiToolInterface` :

```php
<?php

namespace App\Tool;

use Arnaudmoncondhuy\SynapseBundle\Interface\AiToolInterface;

class DateTool implements AiToolInterface
{
    public function getName(): string
    {
        return 'get_current_date';
    }

    public function getDescription(): string
    {
        return 'Retourne la date et l\'heure actuelles au format français';
    }

    public function getParameters(): array
    {
        return []; // Pas de paramètres requis
    }

    public function execute(array $arguments): array
    {
        return [
            'date' => (new \DateTime())->format('d/m/Y H:i:s'),
            'timezone' => date_default_timezone_get(),
        ];
    }
}
```

L'outil sera automatiquement disponible pour l'IA !

## 🎨 Personnalisation de l'Interface

### Variables CSS Overridables

```css
/* assets/styles/synapse-custom.css */
:root {
    --custom-synapse-primary: #ff6b6b;
    --custom-synapse-primary-dark: #ee5a52;
    --custom-synapse-radius: 0.5rem;
    --custom-synapse-bg-sidebar: #1a1a2e;
}
```

### Surcharge de Templates

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
    <link rel="stylesheet" href="{{ asset('synapse-custom.css') }}">
{% endblock %}
```

## 📚 Documentation Complète

- **[VIEWS_INTEGRATION.md](VIEWS_INTEGRATION.md)** : Guide complet d'intégration des vues
- **[USAGE.md](USAGE.md)** : Utilisation avancée et exemples
- **[CONFIGURATION.md](CONFIGURATION.md)** : Référence complète de la configuration

## 🏗️ Architecture

### Couches de Prompts

Le bundle gère les prompts en 3 couches :

1. **Technical Prompt** (Interne) : Règles de formatage et de pensée (`<thinking>`)
2. **System Prompt** (Applicatif) : Votre contexte métier (Date, Rôle, etc.)
3. **User Prompt** : La demande de l'utilisateur

### Sécurité

- **Chiffrement** : Messages chiffrés en base (Sodium)
- **Filtres de contenu** : Protection contre contenus inappropriés
- **Détection de risques** : Système "Ange Gardien" pour modération
- **Rétention** : Suppression automatique des anciennes conversations

### Performance

- **Context Caching** : Réutilisation du contexte (~90% d'économie)
- **Streaming** : Réponses progressives (NDJSON)
- **Thinking Mode** : Raisonnement optimisé (Gemini 2.0+)

## 🧪 Tests

```bash
vendor/bin/phpunit
```

## 📊 Monitoring

L'interface admin propose :

- **Dashboard** : Vue d'ensemble (conversations, risques, coûts)
- **Analytics** : Analyse détaillée de l'usage et des coûts
- **Ange Gardien** : Modération et alertes de sécurité
- **Configuration** : Paramétrage complet du modèle

## 🤝 Contribution

Les contributions sont les bienvenues ! Merci de :

1. Fork le projet
2. Créer une branche (`git checkout -b feature/amazing-feature`)
3. Commit vos changements (`git commit -m 'Add amazing feature'`)
4. Push vers la branche (`git push origin feature/amazing-feature`)
5. Ouvrir une Pull Request

## 📝 Changelog

Voir [CHANGELOG.md](CHANGELOG.md) pour l'historique des versions.

## 📄 Licence

MIT - Voir [LICENSE](LICENSE) pour plus de détails.

## 🙏 Crédits

- **Design Chat** : Inspiré de l'interface Google Gemini
- **Icons** : [Lucide Icons](https://lucide.dev/)
- **Framework** : [Symfony](https://symfony.com/)
- **IA** : [Google Vertex AI](https://cloud.google.com/vertex-ai)

---

**Made with ❤️ by MakerLab**
