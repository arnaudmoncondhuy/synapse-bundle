# Jalon 2 — Migration encyclopédique

Ajoute la table `syn_brain_neuron_encyclopedic` (3ème aire activée — cortex temporal). Permet à `EncyclopedicExtractor` de produire des chunks vectorisés depuis des sources textuelles.

## Tables créées

| Table | Rôle | Référence design |
|---|---|---|
| `syn_brain_neuron_encyclopedic` | Cortex temporal — chunks indexables d'un document | §4 |

## Procédure

### Option A — via DoctrineMigrationsBundle (recommandé)

```bash
bin/console doctrine:migrations:diff
# Vérifier le diff généré contre 001_brain_jalon_2_encyclopedic.sql
bin/console doctrine:migrations:migrate
```

### Option B — SQL manuel

```bash
psql -d votre_base -f migrations-brain-v3/jalon-2/001_brain_jalon_2_encyclopedic.sql
```

## Pré-requis

- Migration jalon-1 appliquée (`syn_brain_memory_source` doit exister pour la FK)

## Notes techniques

- FK `source_uuid` → `syn_brain_memory_source(id)` avec `ON DELETE CASCADE` (RGPD : supprimer une source supprime ses chunks)
- Index `(source_uuid, chunk_index)` composite pour les requêtes "tous les chunks d'un document dans l'ordre"
- `embedding` en JSONB sur PostgreSQL — migration vers pgvector prévue à un jalon ultérieur si la perf devient critique
- Coexistence avec `synapse_rag_document` (ancien) : les deux tables existent en parallèle pendant la transition. Le jalon 8 retirera la table legacy une fois toutes les apps consommatrices migrées

## Rollback

```sql
DROP TABLE IF EXISTS syn_brain_neuron_encyclopedic;
```
