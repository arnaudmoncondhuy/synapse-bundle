# Brain v3 — méthodologie de chantier

> Refonte breaking du bundle Synapse vers un modèle de mémoire à 7 aires cérébrales.
> Branche dédiée : `brain`. Design figé : [../brain-v3-design.md](../brain-v3-design.md).

## Intention

> *Un brain qui sait **associer des idées**.*

Ni un produit pour rivaliser avec Mem0/Letta/Zep, ni un POC jetable. À la fois **application solide** (usage perso, connectable à n'importe quelle app hôte) et **laboratoire** (UI graphe pour voir, comprendre, ajuster les associations).

## Comment lire ces docs

Lecture séquentielle recommandée pour qui découvre le chantier :

1. [00-charte.md](00-charte.md) — intention, principes de discipline, ce qu'on **ne fait pas**
2. [01-roadmap.md](01-roadmap.md) — vue d'ensemble : 8 jalons, critères de sortie, ordre
3. [02-methodologie-sessions.md](02-methodologie-sessions.md) — comment on travaille (rythme, commits, plans, rapports)
4. [03-audit-existant.md](03-audit-existant.md) — cartographie du bundle actuel : ce qui migre, ce qui meurt, ce qui reste
5. [04-references.md](04-references.md) — concurrence vérifiée + papers Hebbiens (sources confirmées, IDs arxiv contrôlés)
6. [07-calibration.md](07-calibration.md) — comment on choisit les bons hyper-paramètres (jeux de synapses concurrents, ADRs chiffrés)

Et les dossiers vivants, alimentés au fil du chantier :

- [05-decisions/](05-decisions/) — ADRs (Architecture Decision Records). Un fichier par décision structurante, datée, contextualisée. [Template](05-decisions/000-template.md).
- [06-phases/](06-phases/) — un plan détaillé par jalon. Le plan est créé **avant** d'attaquer le jalon, validé avec le user, puis exécuté.

## Statut

- ✅ Branche `brain` ouverte (mai 2026), tags v1.0.x supprimés
- ✅ Design doc figé : [../brain-v3-design.md](../brain-v3-design.md)
- ✅ Méthodologie écrite (ce dossier)
- ⏳ Jalon 1 : à planifier (voir [06-phases/jalon-1-fondations.md](06-phases/jalon-1-fondations.md))

## Convention de nommage des fichiers

- `NN-titre.md` (00..) pour les docs structurantes lues séquentiellement
- `NNN-titre.md` (000..) pour les ADRs (numérotés à l'arrivée)
- `jalon-N-titre.md` pour les plans de phase (1..8)
