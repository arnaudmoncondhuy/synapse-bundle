# Bench convergence v1 — résultats

Date : 2026-05-13T05:22:45+00:00

Corpus : `corpus.json` (20 sources)
Annotations : `expected-convergences.json` (9 paires convergent + 6 non-convergent)

## Distributions de similarité (sur paires annotées)

- Paires convergentes attendues : min=0.664, médiane=0.750, max=0.931
- Paires non-convergentes attendues : min=0.457, médiane=0.544, max=0.609

## Métriques par seuil

| Seuil | TP | FP | FN | TN | Précision | Rappel | F1 |
|-------|----|----|----|----|-----------|--------|----|
| 0.65  | 9  | 0  | 0  | 6  | 1.000 | 1.000 | 1.000 |
| 0.70  | 7  | 0  | 2  | 6  | 1.000 | 0.778 | 0.875 |
| 0.75  | 5  | 0  | 4  | 6  | 1.000 | 0.556 | 0.714 |
| 0.80  | 3  | 0  | 6  | 6  | 1.000 | 0.333 | 0.500 |
| 0.85  | 3  | 0  | 6  | 6  | 1.000 | 0.333 | 0.500 |
| 0.90  | 1  | 0  | 8  | 6  | 1.000 | 0.111 | 0.200 |

## Détail au seuil retenu (0.65, F1=1.000)

### Faux positifs (annoté non-convergent, détecté)

_(aucun)_

### Faux négatifs (annoté convergent, non détecté)

_(aucun)_

## Verdict

**Seuil cosine retenu : 0.65** (F1=1.000)

