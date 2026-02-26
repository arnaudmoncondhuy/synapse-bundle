# Bienvenue sur Synapse

Synapse est un écosystème de bundles Symfony pour intégrer des capacités d’Intelligence Artificielle (LLM) au cœur de vos applications. Le projet est organisé en **monorepo** avec trois packages :

- **Synapse Core** (`arnaudmoncondhuy/synapse-core`) : API headless, contrats, ChatService, persistance, RAG, CLI.
- **Synapse Admin** (`arnaudmoncondhuy/synapse-admin`) : interface d’administration pour configurer modèles, presets et outils.
- **Synapse Chat** (`arnaudmoncondhuy/synapse-chat`) : API HTTP et composant front pour le chat (streaming, CSRF).

## Installation rapide

```bash
composer require arnaudmoncondhuy/synapse-core
```

Pour l’interface d’administration et l’UI de chat :

```bash
composer require arnaudmoncondhuy/synapse-admin arnaudmoncondhuy/synapse-chat
```

## Documentation

- **[Synapse Core]** : prise en main, configuration, guides (outils IA, personas, RAG, mémoire), référence technique (contrats, événements, CLI) et architecture.
- **[Synapse Admin]** : utilisation de l’interface d’administration.
- **[Synapse Chat]** : utilisation du bundle chat (routes, CSRF, intégration front).

Utilisez le menu de navigation pour accéder à chaque section.
