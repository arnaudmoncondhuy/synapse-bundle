# Bienvenue sur SynapseBundle 🧠

SynapseBundle est un bundle Symfony puissant et flexible conçu pour intégrer des capacités d'Intelligence Artificielle (LLM) au cœur de vos applications.

## Pourquoi Synapse ?

- **Multi-fournisseurs** : Support natif pour Google Gemini, OpenAI, Anthropic et d'autres via une architecture de clients abstraite.
- **RAG & Mémoire sémantique** : Intégrez vos propres documents à l'IA avec le support natif de **PostgreSQL/pgvector** et du chunking intelligent.
- **Prêt pour la production** : Inclut la gestion de l'historique, la persistance Doctrine, et un indicateur de consommation de tokens.
- **Extensible** : Créez vos propres outils (Function Calling), personas et hooks via le système d'événements Symfony.
- **Admin incluse** : Un tableau de bord prêt à l'emploi pour surveiller vos conversations et configurer vos modèles.

## Installation rapide

```bash
composer require arnaudmoncondhuy/synapse-bundle
```

Ensuite, rendez-vous sur le guide d'**[Installation](getting-started/installation.md)** pour configurer vos clés API.

## Structure de la documentation

*   **[Prise en main](getting-started/installation.md)** : Pour installer et configurer le bundle en quelques minutes.
*   **[Guides](guides/ai-tools.md)** : Pour apprendre à utiliser les fonctionnalités avancées (Outils, Personas, etc.).
*   **[Référence Technique](reference/contracts/index.md)** : Documentation détaillée des interfaces et événements (auto-générée).
*   **[Architecture](explanation/architecture.md)** : Pour comprendre comment Synapse fonctionne sous le capot.
