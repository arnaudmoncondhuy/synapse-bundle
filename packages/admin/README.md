# Synapse Admin

> Admin UI for Synapse — V1 and V2 administration interfaces for managing AI presets, providers, conversations and memories.

Interface d'administration complète pour gérer **Synapse Core** : configuration des providers LLM, création de presets, historique conversationnel, gestion de mémoires, et analytics.

**Dépend de** : `arnaudmoncondhuy/synapse-core`

## Installation

```bash
composer require arnaudmoncondhuy/synapse-admin:^0.1
```

## Caractéristiques

### 🎛️ Admin V2 (Interface moderne)
- **Dashboard** - Vue d'ensemble de l'utilisation
- **Providers** - Configuration des APIs LLM (Gemini, OVH, OpenAI)
  - Gestion des credentials chiffrés
  - Test de connexion
  - Sélection du modèle par provider

- **Presets** - Création et test de configurations IA
  - Paramètres de génération (température, max_tokens, etc.)
  - Paramètres de sécurité (Gemini Safety Settings)
  - Thinking/Extended Thinking support
  - Test en live avec mock data

- **Conversation** - Historique et analyse
  - Historique des conversations
  - Paramètres conversationnels
  - Outils utilisés

- **Mémoire** - Gestion sémantique
  - Configuration des Vector Stores
  - Embeddings management
  - Chunking strategy

- **Sécurité** - Audit et accès
  - API Keys management
  - Audit logs
  - GDPR tools

- **Système** - Monitoring
  - About & Versions
  - Health check
  - Debug logs

### 🎨 Admin V1 (Support rétro-compatible)
- Interface classique Symfony
- Tous les formulaires de configuration
- Analytics détaillées

### 🔒 Sécurité
- `AdminSecurityTrait` - Vérification des droits via `DefaultPermissionChecker`
- Intégration Symfony Security
- Chiffrement des credentials sensibles

### 🎯 Features avancées
- **Hybrid HTML/JSON form pattern** - Formulaires générés dynamiquement
- **Provider-agnostic UI** - Les champs s'adapent au provider sélectionné
- **Live preset testing** - Test les presets sans vraie requête LLM
- **NDJSON streaming** - Réponses streamées en real-time

## Configuration

**config/bundles.php** - Ajouter le bundle :
```php
ArnaudMoncondhuy\SynapseAdmin\SynapseAdminBundle::class => ['all' => true],
```

**config/routes.yaml** - Enregistrer les routes :
```yaml
synapse_admin:
    resource: '@SynapseAdminBundle/config/routes.yaml'
    prefix: /synapse/admin-v2
```

**config/packages/security.yaml** - Protéger les routes admin :
```yaml
access_control:
    - { path: ^/synapse/admin, roles: ROLE_ADMIN }
    - { path: ^/synapse, roles: ROLE_ADMIN } # Chat admin aussi
```

## Routes disponibles

### Admin V2 Dashboard
- `GET /synapse/admin-v2` - Dashboard principal
- `GET /synapse/admin-v2/intelligence/providers` - Gestion des providers
- `GET /synapse/admin-v2/intelligence/presets` - Gestion des presets
- `POST /synapse/admin-v2/intelligence/presets` - Créer preset
- `GET /synapse/admin-v2/intelligence/models` - Modèles disponibles
- `GET /synapse/admin-v2/conversation/history` - Historique conversations
- `GET /synapse/admin-v2/memoire/embeddings` - Gestion embeddings
- `GET /synapse/admin-v2/securite/api_keys` - API Keys
- `GET /synapse/admin-v2/securite/audit` - Logs d'audit
- `GET /synapse/admin-v2/systeme/health` - Health check
- `GET /synapse/admin-v2/systeme/debug` - Debug info

### Admin V1 (Rétro-compatible)
- `GET /synapse/admin/dashboard`
- `GET /synapse/admin/providers`
- `GET /synapse/admin/presets`
- Etc.

## Twig Namespaces

Les templates sont accessibles via `@Synapse` :

```twig
{% include '@Synapse/admin_v2/layout/base.html.twig' %}
{% include '@Synapse/admin/layout.html.twig' %}
```

## Structure des dépendances

```
synapse-admin
  ├── arnaudmoncondhuy/synapse-core
  ├── symfony/twig-bundle
  ├── symfony/asset-mapper
  ├── symfony/stimulus-bundle
  └── symfony/asset
```

## Intégration avec Synapse Core

Le bundle auto-découvre les contrôleurs et services du core :
- Services de configuration via `DatabaseConfigProvider`
- Formulaires de preset/provider
- Gestion des outils enregistrés

## Licence

PolyForm Noncommercial 1.0.0 (usage non-commercial uniquement)

## Support

- 📖 [Documentation Admin](https://arnaudmoncondhuy.github.io/synapse-bundle/admin/)
- 🐛 [Issues](https://github.com/arnaudmoncondhuy/synapse-bundle/issues)

## Auteur

[Arnaud Moncondhuy](https://github.com/arnaudmoncondhuy)
