# Architecture Decision Records (ADR)

> Décisions structurantes du chantier Brain v3. Une décision = un fichier numéroté.

## Format

Chaque ADR suit le [template](000-template.md) :

- **Statut** : proposé / accepté / superseded / abandonné
- **Date** : YYYY-MM-DD
- **Jalon** : N (jalon de la roadmap où la décision se pose)
- **Contexte** — pourquoi maintenant
- **Options considérées** — au moins 2, sinon ce n'est pas une décision
- **Décision** — option retenue + justification
- **Conséquences** — code, migrations, tests, docs, ADRs ouverts

## Quand écrire un ADR

Tout choix qui :

- Conditionne du code structurant (modèle de données, contrat public, schéma SQL, point d'extension)
- S'écarte du design figé (`docs/brain-v3-design.md`)
- Sera dur à inverser plus tard

Pas pour les choix esthétiques internes (nommage à la marge, ordre des méthodes).

## Index

| # | Titre | Statut | Jalon |
|---|---|---|---|
| [000](000-template.md) | Template | — | — |
| [001](001-prefixe-table-configurable.md) | Préfixe SQL configurable via `synapse.persistence.table_prefix` | accepté | 1 |
| [002](002-pas-de-tenant-id-jalon-1.md) | Pas de `tenant_id` sur `MemorySource` au jalon 1 | accepté | 1 |
| [003](003-extracteur-llm-structured-output.md) | Extracteur LLM via structured output (JSON schema) | accepté | 2 |
| [004](004-prompts-en-resources-files.md) | Prompts d'extracteurs en fichiers Resources, pas en BDD | accepté | 2 |
| [005](005-seuil-cosine-convergence.md) | Seuil cosine pour la convergence mémorielle | **accepté** (0.65 calibré 2026-05-13) | 3 |
| [006](006-isolation-user-synapses.md) | Garde-fou isolation user sur les synapses | accepté | 3 |
| [007](007-profondeur-bfs-spreading-activation.md) | Profondeur BFS du spreading activation : 2 sauts par défaut | accepté | 4 |
| [008](008-formule-score-spreading-activation.md) | Formule de score : linéaire avec decay exponentiel (0.7) | accepté | 4 |

## Convention de numérotation

Numérotation **monotone**, attribuée au moment où l'ADR est ouvert. Pas de "trou" comblé a posteriori. Un ADR superseded reste en place avec son numéro, mais son statut indique l'ADR qui le remplace.
