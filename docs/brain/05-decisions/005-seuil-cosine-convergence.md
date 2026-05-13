---
status: accepté
date: 2026-05-13
jalon: 3
---

# ADR-005 — Seuil cosine pour la convergence mémorielle

## Statut

`accepté` — calibration empirique terminée le 2026-05-13 sur corpus weecom v1 (20 notes, 15 paires annotées en aveugle).

## Contexte

Le `ConvergenceDetector` du jalon 3 doit décider si deux neurones d'une même aire sont "convergents" (i.e. portent sur le même fait/événement) sur la base de la **similarité cosine** entre leurs embeddings.

Quel seuil retenir ? Trop bas → faux positifs (synapses parasites entre faits non liés). Trop haut → faux négatifs (convergences manquées, neurones dupliqués).

La charte §2.10 interdit le réglage à l'œil : il faut **comparer plusieurs sets de paramètres** sur fixtures annotées et trancher chiffré.

## Options considérées

### Option A — Valeur "raisonnable a priori" (ex: 0.85)

Choisir un seuil consensuel sur la littérature LLM (sentence-transformers utilisent souvent 0.85 ou 0.9 pour la déduplication).

**Rejeté** : la calibration a montré qu'à 0.85 le rappel s'effondre à 0.333 avec le modèle `text-multilingual-embedding-002`. Confirmation empirique qu'« à l'œil = se mentir » (charte §2.10).

### Option B — Calibration sur N sets sur corpus annoté

Cf. méthodologie [07-calibration.md](../07-calibration.md). 6 sets testés (0.65, 0.70, 0.75, 0.80, 0.85, 0.90) sur le corpus `tests/Brain/Quality/Fixtures/convergence-v1/`.

**Retenu**.

## Décision

**Seuil cosine retenu : `0.65`** pour le modèle d'embedding `text-multilingual-embedding-002` (768 dim, Vertex AI europe-west1).

### Chiffres mesurés

Corpus : 20 notes Pipedrive réelles weecom, 15 paires annotées en aveugle (9 convergentes attendues + 6 non-convergentes attendues).

| Seuil | TP | FP | FN | TN | Précision | Rappel | F1 |
|-------|----|----|----|----|-----------|--------|----|
| **0.65** | **9** | **0** | **0** | **6** | **1.000** | **1.000** | **1.000** |
| 0.70 | 7 | 0 | 2 | 6 | 1.000 | 0.778 | 0.875 |
| 0.75 | 5 | 0 | 4 | 6 | 1.000 | 0.556 | 0.714 |
| 0.80 | 3 | 0 | 6 | 6 | 1.000 | 0.333 | 0.500 |
| 0.85 | 3 | 0 | 6 | 6 | 1.000 | 0.333 | 0.500 |
| 0.90 | 1 | 0 | 8 | 6 | 1.000 | 0.111 | 0.200 |

### Distributions de similarité (justification)

- Paires convergentes attendues : min=0.664, médiane=0.750, max=0.931
- Paires non-convergentes attendues : min=0.457, médiane=0.544, **max=0.609**

Il existe un écart franc entre les distributions (max non-convergent 0.609 < min convergent 0.664). Tout seuil dans `[0.610, 0.663]` aurait F1=1. On retient **0.65** comme valeur centrale conservatrice qui :
- Maximise la marge contre les faux positifs (0.65 - 0.609 = 0.041)
- Garde une marge contre les faux négatifs (0.664 - 0.65 = 0.014)

## Conséquences

- **Code** : `ConvergenceDetector::DEFAULT_COSINE_THRESHOLD` passé de 0.85 → **0.65**
- **Documentation** : le seuil est valable **uniquement** pour `text-multilingual-embedding-002` (Vertex AI). Si on change de modèle d'embedding (ex: text-embedding-3-large d'OpenAI, ou pgvector + un autre), recalibrer obligatoirement (cf. [07-calibration.md](../07-calibration.md) §"Quand recalibrer")
- **Migrations** : aucune (paramètre runtime)
- **Tests** : `ConvergenceDetectorTest` à mettre à jour si certains tests assertent sur 0.85 — sinon valeur par défaut suffit

## Limites assumées

1. **Échantillon réduit** (15 paires annotées) : F1=1.0 est probablement optimiste. Un échantillon élargi (50-100 paires) pourrait révéler des cas-frontière. À refaire au jalon 4 sur un corpus plus large
2. **Annotations par un seul annotateur** : pas d'inter-annotator agreement. Risque de biais subjectif (« je trouve que ces 2 sources se ressemblent » vs vraie convergence d'usage). À traiter par double annotation indépendante au jalon 4+
3. **Corpus de type unique (notes)** : weecom propose aussi `activities` et `deals`, structurés différemment. Ces types peuvent avoir des distributions de similarité différentes. Recalibrer si on les utilise massivement
4. **Modèle multilingual** : text-multilingual-embedding-002 est conçu pour 100+ langues, donc plus lâche en français pur qu'un modèle fr-only. Le seuil 0.65 reflète cette lâcheté

## Notes

- **Apprentissage** : le placeholder 0.85 (heuristique a priori) était **catastrophique** (rappel 33%). La calibration a sauvé une régression silencieuse. Confirmation forte de la charte §2.10
- Re-calibration prévue à chaque changement d'embedding model
- Si la distribution se rapproche (overlap entre convergent et non-convergent), envisager filtre LLM-as-judge en complément (cf. ADR-005 v1 — Option Reportée). Tant que la marge est franche (>0.05 ici), pas nécessaire

---

*Calibration effectuée le 2026-05-13 avec `tools/brain-bench/bench-convergence.php`. Rapport complet : [`tests/Brain/Quality/Fixtures/convergence-v1/bench-report.md`](../../../packages/core/tests/Brain/Quality/Fixtures/convergence-v1/bench-report.md).*
