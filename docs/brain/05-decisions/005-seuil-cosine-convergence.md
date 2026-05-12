---
status: proposé
date: 2026-05-13
jalon: 3
---

# ADR-005 — Seuil cosine pour la convergence mémorielle

## Statut

`proposé` — placeholder à compléter empiriquement pendant le jalon 3 (étape 10 du plan : calibration sur 3 sets concurrents).

## Contexte

Le `ConvergenceDetector` du jalon 3 doit décider si deux neurones d'une même aire sont "convergents" (i.e. portent sur le même fait/événement). Le critère le plus simple : **similarité cosine entre leurs embeddings**.

Quel seuil retenir ? Trop bas → faux positifs (synapses parasites entre faits non liés). Trop haut → faux négatifs (convergences manquées, neurones dupliqués).

La charte §2.10 interdit le réglage à l'œil : il faut **comparer plusieurs sets de paramètres** sur fixtures annotées et trancher chiffré.

## Options considérées

### Option A — Valeur "raisonnable a priori" (ex: 0.85)

Choisir un seuil consensuel sur la littérature LLM (sentence-transformers utilisent souvent 0.85 ou 0.9 pour la déduplication).

**Avantages** : rapide, pas de bench.
**Inconvénients** : pas calibré sur notre corpus. Charte §2.10 explicitement violée — "régler à l'œil = se mentir".

### Option B — Calibration sur 3 sets

Conformément à [07-calibration.md](../07-calibration.md) :

- `v1-cosine-075` : seuil 0.75
- `v1-cosine-085` : seuil 0.85 (candidat baseline)
- `v1-cosine-095` : seuil 0.95

Pour chaque set, on lance `brain:bench:convergence` sur le corpus de fixtures annotées (15-20 sources weecom, paires de convergences attendues annotées en aveugle).

Métriques :
- Précision (TP / (TP + FP)) — seuil élevé attendu pour de la qualité d'association
- Rappel (TP / (TP + FN)) — seuil élevé attendu pour ne pas manquer de convergence
- F1 harmonique

On retient le seuil qui maximise F1, en privilégiant la précision en cas d'égalité (un faux positif pollue le retrieval plus qu'un faux négatif).

**Avantages** : ancré dans la réalité du corpus, justifiable, reproductible.
**Inconvénients** : nécessite les fixtures annotées (étape 9 du plan jalon 3) et l'outil bench (étape 9).

## Décision

**Option B retenue.**

La calibration sera effectuée à l'étape 10 du jalon 3 (`feat(brain): calibration cosine v1`). Cette ADR sera **complétée avec les chiffres mesurés** au moment où la calibration aura lieu, puis passée en `accepté`.

Tant qu'on n'a pas mesuré, le placeholder pour le code est `0.85` (heuristique raisonnable pour démarrer le développement, à remplacer par la valeur calibrée).

## Conséquences

- **Code impacté** : `ConvergenceDetector::detectAndLink($candidate, float $cosineThreshold = 0.85)` — paramètre avec defaut placeholder, remplaçable au runtime
- **Bench** : `tools/brain-bench/calibration-convergence.php` à créer (génère les 3 sets, lance le bench, produit un rapport markdown)
- **Fixtures** : `tests/Brain/Quality/Fixtures/convergence-v1/` versionné, annoté en aveugle avant écriture du ConvergenceDetector pour éviter le biais juge-et-partie
- **ADRs à suivre** : si la calibration montre que le cosine seul est insuffisant (F1 < 0.65), nouvel ADR sur l'ajout d'un filtre LLM-as-judge (coûteux mais précis)

## Notes

- Le seuil pourra être recalibré à chaque changement de modèle d'embedding (cf. 07-calibration.md §"Quand recalibrer")
- Le seuil est **par aire** potentiellement — les embeddings d'un EncyclopedicNeuron (chunk long) ont des propriétés différentes des SemanticNeuron (fait court). Si la mesure révèle un besoin, on aura 1 seuil par aire (à acter dans cet ADR au moment de la calibration)

---

*À compléter empiriquement pendant l'étape 10 du jalon 3.*
