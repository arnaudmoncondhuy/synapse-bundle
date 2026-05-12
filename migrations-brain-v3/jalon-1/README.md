# Jalon 1 — Migration des fondations

Ce jalon crée les 4 tables nécessaires aux fondations Brain v3.

## Tables créées

| Table | Rôle | Référence design |
|---|---|---|
| `syn_brain_memory_source` | Point d'entrée stimuli | §4 |
| `syn_brain_neuron_episodic` | Hippocampe (événements situés) | §4 |
| `syn_brain_neuron_semantic` | Néocortex (faits stables) | §4 |
| `syn_brain_synapse` | Connexion polymorphe 5 dimensions | §6 |

## Procédure

### Option A — via DoctrineMigrationsBundle (recommandé)

Côté app hôte :

```bash
bin/console doctrine:migrations:diff
# Vérifier le diff généré contre 001_brain_jalon_1_create_tables.sql
bin/console doctrine:migrations:migrate
```

### Option B — SQL manuel

Si vous gérez vos migrations sans DoctrineMigrationsBundle :

```bash
psql -d votre_base -f migrations-brain-v3/jalon-1/001_brain_jalon_1_create_tables.sql
```

## Préfixe personnalisé

Si vous avez surchargé `synapse.persistence.table_prefix` (par exemple `acme_`), remplacez `syn_` par `acme_` dans le SQL avant exécution.

## Notes techniques

- Type UUID natif PostgreSQL. Pour MySQL/MariaDB, remplacer par `CHAR(36)` et adapter les index.
- Type JSON natif. Sur PostgreSQL, envisager `JSONB` pour les payloads volumineux (meilleure indexation).
- L'embedding est stocké en JSON pour la portabilité. Une migration vers `pgvector` est prévue à un jalon ultérieur (perf retrieval).
- La FK `source_uuid` sur `syn_brain_neuron_episodic` est `ON DELETE CASCADE` — supprimer une source supprime ses neurones (RGPD-friendly, droit à l'oubli automatique).
- Pas de FK SQL stricte sur la `syn_brain_synapse` côté `source_neuron_id` / `target_neuron_id` : l'intégrité polymorphe est gérée en code via le contrat `MemoryFragment`. C'est un compromis assumé (charte §2.3).

## Rollback

```sql
DROP TABLE IF EXISTS syn_brain_synapse;
DROP TABLE IF EXISTS syn_brain_neuron_semantic;
DROP TABLE IF EXISTS syn_brain_neuron_episodic;
DROP TABLE IF EXISTS syn_brain_memory_source;
```
