# SynapseBundle

A reusable Symfony bundle for AI chatbot integration with Google Gemini.

## Features

- 🤖 Google Gemini API integration (gemini-2.5-flash-lite)
- 🔧 Function Calling / Tools support
- 📡 Streaming responses (NDJSON)
- 💾 Conversation history (Session-based, extensible)
- 🎨 Ready-to-use Twig component + Stimulus.js controller
- 🔌 Fully extensible via interfaces

## Requirements

- PHP 8.4+
- Symfony 7.0+

## Installation

```bash
composer require arnaudmoncondhuy/synapse-bundle
```

## Configuration

# config/packages/synapse.yaml
synapse:
    # Optionnel si vous utilisez un provider dynamique
    api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.5-flash-lite'

## Usage

> 📖 **Documentation Complète** : Voir [USAGE.md](USAGE.md) pour les détails d'implémentation avancée.

### Architecture des Prompts
Le bundle gère les prompts en 3 couches :
1. **Technical Prompt** (Interne) : Règles de formatage et de pensée (`<thinking>`).
2. **System Prompt** (Applicatif) : Votre contexte métier (Date, Rôle...).
3. **User Prompt** : La demande de l'utilisateur.

### Integration Rapide

Ajoutez le composant chat dans votre template Twig :

```twig
{{ include('@Synapse/chat/component.html.twig') }}
```

### Créer des Outils
Implémentez simplement `AiToolInterface`. Vos services seront automatiquement détectés.

```php
class DateTool implements AiToolInterface { ... }
```

Voir [USAGE.md](USAGE.md#-%EF%B8%8F-cr%C3%A9er-des-outils-tools) pour un exemple complet.

## License

MIT
