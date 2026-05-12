---
status: accepté
date: 2026-05-12
jalon: 1
---

# ADR-002 — Pas de `tenant_id` sur `MemorySource` au jalon 1

## Statut

`accepté` — décidé pendant l'audit du jalon 1, avant clôture.

## Contexte

Le design figé (`docs/brain-v3-design.md` §4) liste `tenant_id UUID` comme l'un des champs de `syn_brain_memory_source`. L'audit `brain-code-reviewer` du jalon 1 a relevé que ce champ est **absent** de l'entité Doctrine et de la migration SQL — sans ADR justifiant la divergence.

Comportement attendu par la charte §6 (cohérence design figé) : soit aligner l'implémentation sur le design, soit publier un ADR de divergence justifiée. Cet ADR formalise la divergence.

## Options considérées

### Option A — Ajouter `tenant_id` dès le jalon 1

Ajout d'une propriété `?Uuid $tenantId` à `MemorySource`, colonne SQL `tenant_id UUID NULL`, index sur cette colonne.

**Avantages** :
- Aligné strictement sur le design figé
- Permet multi-tenancy natif sans migration ultérieure
- Schéma stable pour les premières apps hôtes consommatrices

**Inconvénients** :
- Aucune app cible n'a besoin de multi-tenant à court terme (cf. [[project-brain-v3-intent]] : usage perso d'abord)
- Champ qui resterait `NULL` partout pendant des mois → pollue les requêtes
- Sémantique tenant vs owner pas encore tranchée (un owner peut-il appartenir à plusieurs tenants ? un tenant a-t-il des owners ?)

### Option B — Différer à un jalon ultérieur, garder seulement `owner_id`

Pas de `tenant_id` au jalon 1. `owner_id UUID NULL` suffit pour les cas mono-tenant (la résolution vers une entité applicative est de la responsabilité de l'app hôte, cf. PHPDoc).

**Avantages** :
- Schéma plus simple (YAGNI sur du multi-tenant non prouvé)
- Pas de NULL partout
- Cohérent avec l'intention "usage perso d'abord, connectable n'importe où au besoin" (cf. mémoire user)
- Tranche le débat sémantique plus tard, quand un cas réel se présente

**Inconvénients** :
- Divergence assumée vs design figé (nécessite cet ADR)
- Migration ultérieure si multi-tenant émerge (ajout de colonne nullable = peu coûteux)

## Décision

**Option B retenue : pas de `tenant_id` au jalon 1.**

Justification :

1. **YAGNI strict** (charte §2.4 : *"construire pour 1, abstraire pour N"*). Aucune app cible ne réclame le multi-tenant maintenant.
2. **Coût migration ultérieure faible** : ajouter une colonne nullable + un index sera une migration `ALTER TABLE … ADD COLUMN` sans recopie de données.
3. **Sémantique pas tranchée** : tenant vs owner n'est pas un débat productif tant qu'on n'a pas un cas concret pour le câbler.
4. **Cohérence avec l'intention du projet** : Brain v3 vise d'abord l'usage perso ; le multi-tenant est un sujet "produit", pas "qualité d'association".

Le design figé reste la référence — quand un besoin réel de multi-tenant émergera, on réalignera (et on supersedera cet ADR).

## Conséquences

- **Code impacté** : aucune modification de l'entité `MemorySource` (le champ n'est tout simplement pas ajouté)
- **Migrations** : `migrations-brain-v3/jalon-1/001_brain_jalon_1_create_tables.sql` reste tel quel
- **Tests** : aucun
- **Documentation** : design figé `docs/brain-v3-design.md` §4 reste textuellement intact — c'est cet ADR qui acte la divergence pour les implémenteurs
- **ADRs à suivre** :
  - Si multi-tenant nécessaire un jour : nouvel ADR `0XX-multi-tenant-brain` qui supersede celui-ci et décrit la migration

## Notes

- Reformulation côté audit `brain-code-reviewer` (rapport jalon 1 point B) : *"un schéma multi-tenant qu'on découvre au jalon 3 oblige à une migration douloureuse"*. Réponse : la migration sera `ALTER TABLE … ADD COLUMN tenant_id UUID NULL` + index, soit ~3 lignes SQL. Acceptable.
- Si une app hôte multi-tenant est intégrée avant l'ajout du champ, elle peut **encoder le tenant dans `provider`** (ex: `provider = 'webhook_tenant42'`) en attendant — ce n'est pas idéal mais ça débloque sans toucher au schéma.

---

*Décidé après audit du jalon 1 le 2026-05-12.*
