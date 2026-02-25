# Entités Doctrine

SynapseBundle utilise des `MappedSuperclass` que vous devez étendre dans votre application pour activer la persistance.

## Entités principales

- **SynapseConversation** : Stocke les métadonnées de la discussion (titre, propriétaire, date).
- **SynapseMessage** : Stocke le contenu des échanges (rôle, contenu texte, calls outils).

## Entités de configuration

- **SynapsePreset** : Configuration technique d'un modèle (température, outils, etc.).
- **SynapseProvider** : Credentials chiffrés pour les fournisseurs (Gemini, OpenAI).

> [!NOTE]
> Reportez-vous au guide de [Persistance](../guides/rle-management.md) pour les détails d'implémentation.
