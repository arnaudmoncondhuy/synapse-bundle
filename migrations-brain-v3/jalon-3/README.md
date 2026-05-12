# Jalon 3 — Migration multi-aires + isolation user

Ajoute la table `syn_brain_neuron_procedural` (4ème aire activée — ganglions) et 2 colonnes `owner_id` sur `syn_brain_synapse` (garde-fou isolation user, ADR-006).

## Tables / colonnes créées

| Migration | Cible | Rôle |
|---|---|---|
| 001 | `syn_brain_neuron_procedural` | Aire ganglions — workflows typés avec successRate Hebbien |
| 002 | `syn_brain_synapse.source_neuron_owner` + `.target_neuron_owner` (+ index) | Garde-fou isolation user (ADR-006) + filtrage rapide spreading activation |

## Procédure

### Option A — via DoctrineMigrationsBundle (recommandé)

```bash
bin/console doctrine:migrations:diff
# Vérifier le diff généré contre les fichiers .sql de référence
bin/console doctrine:migrations:migrate
```

### Option B — SQL manuel

```bash
psql -d votre_base -f migrations-brain-v3/jalon-3/001_brain_jalon_3_procedural.sql
psql -d votre_base -f migrations-brain-v3/jalon-3/002_brain_jalon_3_synapse_owners.sql
```

## Pré-requis

- Migrations jalon-1 et jalon-2 appliquées (`syn_brain_memory_source` + `syn_brain_synapse` doivent exister)

## Notes techniques

### syn_brain_neuron_procedural

- FK `source_uuid` → `syn_brain_memory_source(id)` avec **ON DELETE SET NULL** (et non CASCADE) : une procédure manuelle peut survivre à la disparition de la source qui l'a inspirée (charte §2.4 : "construire pour 1, abstraire pour N" — l'admin doit pouvoir éditer ses routines)
- Pas d'embedding (recherche par trigger pattern, pas par similarité)
- 3 index : `source_uuid`, `name`, `last_executed_at` (pour le cron de pruning du jalon 8)

### syn_brain_synapse — colonnes owner

- 2 colonnes UUID nullables : `source_neuron_owner` et `target_neuron_owner`
- 2 index dédiés pour le filtrage spreading activation par owner (jalon 4)
- Migration douce : les anciennes synapses (jalon 1 et 2) ont `NULL` partout → traitées comme "open" (admissibles selon la règle)
- Pas de contrainte CHECK sur `(source_neuron_owner = target_neuron_owner OR source_neuron_owner IS NULL OR target_neuron_owner IS NULL)` — l'invariant est porté en code via `Synapse::__construct` (ADR-006 option A). Si un jalon ultérieur identifie un chemin bypass, on pourra ajouter une contrainte SQL en complément

## Rollback

```sql
ALTER TABLE syn_brain_synapse DROP COLUMN source_neuron_owner;
ALTER TABLE syn_brain_synapse DROP COLUMN target_neuron_owner;
DROP TABLE IF EXISTS syn_brain_neuron_procedural;
```
