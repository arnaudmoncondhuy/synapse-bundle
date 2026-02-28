# Bienvenue sur Synapse

Synapse est un écosystème de bundles Symfony pour intégrer des capacités d’Intelligence Artificielle (LLM) au cœur de vos applications. Le projet est organisé en **monorepo** avec trois packages :

- **Synapse Core** (`arnaudmoncondhuy/synapse-core`) : API headless, contrats, **Accounting (coûts)**, **Quotas**, Missions & Tons, RAG, CLI (**Doctor**).
- **Synapse Admin** (`arnaudmoncondhuy/synapse-admin`) : interface d’administration **V2** (Dashboard, Analytics, Monitoring).
- **Synapse Chat** (`arnaudmoncondhuy/synapse-chat`) : API HTTP, composant front, **Auto-titling** et sécurité CSRF.

## Installation rapide

```bash
composer require arnaudmoncondhuy/synapse-core
php bin/console synapse:doctor --init
```

## Documentation

- **[Synapse Core]** : Prise en main, Accounting, Quotas, Missions, RAG et CLI (Doctor).
- **[Synapse Admin]** : Utilisation de l’interface d’administration V2 et Analytics.
- **[Synapse Chat]** : Utilisation du bundle chat, auto-titling et sécurité.

Utilisez le menu de navigation pour accéder à chaque section.
