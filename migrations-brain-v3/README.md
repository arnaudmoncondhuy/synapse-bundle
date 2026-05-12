# Migrations Brain v3

> Scripts SQL de référence pour les apps hôtes consommatrices.

Le bundle Synapse ne gère pas ses propres migrations — chaque application hôte est responsable d'appliquer les changements de schéma via son `DoctrineMigrationsBundle`. Ce dossier fournit des **SQL de référence** par jalon, pour faciliter cette tâche.

## Procédure recommandée (côté app hôte)

1. **Mettre à jour le bundle** : `composer update arnaudmoncondhuy/synapse-core`
2. **Générer la migration auto** : `bin/console doctrine:migrations:diff`
3. **Vérifier le diff** contre le SQL de référence du jalon correspondant (ce dossier)
4. **Appliquer** : `bin/console doctrine:migrations:migrate`

Le SQL de référence est en **PostgreSQL** (SGBD recommandé en production). Doctrine adaptera pour MySQL/MariaDB côté hôte si nécessaire.

## Préfixe de table

Toutes les tables Brain utilisent le préfixe configurable `synapse.persistence.table_prefix` (défaut `syn_`). Le SQL ci-dessous suppose le préfixe par défaut. Si vous avez surchargé via `config/packages/synapse.yaml`, remplacez `syn_` par votre valeur.

## Jalons

- [Jalon 1 — Fondations](jalon-1/) — `brain_memory_source`, `brain_neuron_episodic`, `brain_neuron_semantic`, `brain_synapse`
- Jalon 2 — Ingestion mono-aire (à venir)
- ...

Cf. [docs/brain/](../docs/brain/) pour le plan complet.
