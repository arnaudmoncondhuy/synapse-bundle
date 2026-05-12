---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 4 — Retrieval Hebbien simple (spreading activation)

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Retrieval enrichi par spreading activation.** Une requête déclenche des neurones candidats, puis leurs voisins via synapses (2-3 sauts), filtrés par aire et score. Premier retrieval Hebbien opérationnel.

## 2. Test de sortie

```bash
bin/console brain:query "donne-moi le brief du dossier X"
# → 10-20 neurones top retrieval (avec scores)
# → comparable / supérieur à un retrieval vector simple sur même requête

bin/console brain:bench:retrieval --baseline=vector --variant=hebbian
# → F1 Hebbian ≥ F1 vector sur échantillon annoté (≥10 requêtes)
```

## 3. Pré-requis

- Jalon 3 livré (synapses peuplées par convergence)
- Synapses auto-générées par co-activation : à implémenter dans ce jalon (mode A du design §10)

## 4. Surface à concevoir

- `Brain\Service\MemoryRetriever` — spreading activation
- `Brain\Event\BrainContextSubscriber` — remplace `MemoryContextSubscriber` + `RagContextSubscriber` en phase ENRICH
- `Brain\Service\HebbianReinforcer` — incrémente weight des synapses co-activées dans la même requête
- Algorithme : extraction prompt → seeds → BFS bornée → score combiné (weight × distance × aire × confidence)

## 5. Étapes d'implémentation

À détailler. **Recommandation** : ouvrir Plan mode pour ce jalon (complexité algorithmique).

## 6. Tests & dogfooding

### Tests PHPUnit
- Tests unitaires `MemoryRetrieverTest` (graphe synthétique, vérifier ordre de retrieval attendu)
- Tests unitaires `HebbianReinforcerTest` (vérifier delta de poids après co-activation)

### Validation qualité synapses

- Échantillon weecom : 10 requêtes en langage naturel + annotations "neurones attendus dans le top 10"
- Métrique : **F1 retrieval** Hebbian ≥ baseline vector simple sur même corpus
- Fixtures `tests/Brain/Quality/Fixtures/retrieval-v1/` versionné

## 7. Décisions ouvertes

- ADR-XXX : profondeur max BFS (2 sauts ? 3 ? configurable ?)
- ADR-XXX : formule de score combiné (linéaire ? log ? pondération par jalon ultérieur via functional network ?)
- ADR-XXX : reinforcement automatique vs gated ?

## 8. Hors-scope

- Polarity inhibitoire (jalon 5)
- Functional networks pour pondérer le score (jalon 6)

## 9. Bilan

*À remplir à la fin du jalon.*
