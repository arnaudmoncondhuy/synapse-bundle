---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 3 — Ingestion multi-aires + convergence

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Convergence mémorielle.** Deux sources différentes produisent des neurones similaires dans la même aire (embeddings proches) → détectés et marqués comme corroborants. Cf. design §9.

## 2. Test de sortie

```bash
# Ingestion d'une source qui active 3+ aires en une passe
bin/console brain:ingest:test --source-id=<deal_id> --multi-area
# → produit ≥2 neurones dans ≥2 aires différentes

# Test convergence
bin/console brain:bench:convergence --threshold=0.85
# → précision ≥ 0.8 sur fixtures versionnées
```

## 3. Pré-requis

- Jalon 2 livré (extracteurs mono-aire opérationnels)
- ADRs jalon 2 validés
- Outils de bench `tools/brain-bench/` opérationnels (cf. méthodologie §validation-qualité)

## 4. Surface à concevoir

- `Brain\Service\MemoryExtractor::extractAll()` — orchestrateur multi-aires
- `Brain\Service\ConvergenceDetector` — calcule similarité embeddings entre neurones, marque convergences
- Synapses auto de type `CORROBORATES` (edge_type=ASSOCIATION) entre neurones convergents
- 4ème aire ajoutée : `ProceduralNeuron` (workflow détecté dans la source) si présent

## 5. Étapes d'implémentation

À détailler.

## 6. Tests & dogfooding

### Tests PHPUnit
- Tests unitaires extracteurs + ConvergenceDetector

### **Validation qualité synapses (premier jalon concerné — charte §2.9)**

- Échantillon weecom (corpus de dogfooding, charte §2.1) : 15 sources réelles annotées manuellement, choisies pour représenter une variété structurelle — 5 entités contractuelles, 5 contacts, 5 événements/notes. Ces catégories sont du **vocabulaire weecom**, pas des concepts Brain — on s'en sert uniquement pour avoir un échantillon réaliste hétérogène
- Métriques attendues :
  - **Taux de convergence** : 2 sources sémantiquement similaires → ≥80% de neurones identifiés comme convergents
  - **Précision convergence** : ≥0.7 (peu de faux positifs)
  - **Rappel convergence** : ≥0.6 sur l'échantillon annoté
- Fixtures de régression : `tests/Brain/Quality/Fixtures/convergence-v1/` versionné
- Outil dédié : `bin/console brain:bench:convergence`

## 7. Décisions ouvertes

- ADR-XXX : seuil cosine pour considérer 2 embeddings "convergents" — empirique, à mesurer
- ADR-XXX : convergence détectée auto ou validée par user via UI ? (cf. validation-gated jalon 8)
- **ADR-XXX (garde-fou critique) : isolation user sur Synapse** — implémentation : assertion code, trigger SQL, ou les deux ?
  Règle d'admissibilité (cf. [[feedback-brain-user-isolation]]) :
  - (privé X, privé X) → OK
  - (privé X, open=NULL) → OK (l'open enrichit le privé, couche partagée)
  - (open, open) → OK
  - (privé X, privé Y) avec X ≠ Y → **interdit**

## 8. Hors-scope

- Spreading activation (jalon 4)
- Polarity et relation_type fines (jalon 5)
- Functional networks (jalon 6)

## 9. Bilan

*À remplir à la fin du jalon.*
