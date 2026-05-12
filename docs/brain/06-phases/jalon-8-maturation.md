---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 8 — Maturation (decay, consolidation, validation-gated, créativité)

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Créativité / liens non-évidents** mesurée + **maturation** opérationnelle (decay par aire, consolidation cross-aires, validation-gated learning). Le brain est prêt pour intégration dans les autres apps consommatrices.

## 2. Test de sortie

```bash
# Cron decay
bin/console brain:decay:run
# → synapses non activées depuis X jours voient leur weight diminuer selon courbe par aire

# Cron consolidation
bin/console brain:consolidate:run
# → épisodique répété → sémantique stable (cf. sommeil REM, design §8)

# Validation-gated
# Lors d'un Brain.linkNeurons() proposé par LLM :
# → score auto = LLM confidence × user rating moyen × historical accuracy
# → si score < seuil : synapse marquée pending, à valider par user dans UI

# Créativité
bin/console brain:bench:creativity
# → ≥1 association cross-aires non-évidente par session sur corpus weecom
```

## 3. Pré-requis

- Jalon 7 livré (toutes aires + UI)
- ADRs jalons précédents tous tranchés

## 4. Surface à concevoir

- `Brain\Service\MemoryDecayService` (cron, paramètres par aire depuis config)
- `Brain\Service\ConsolidationService` (cron, transfert épisodique → sémantique)
- `Brain\Service\ConsolidationGate` (validation-gated, cf. Kairos)
- Métriques créativité dans `brain:bench:creativity` (à définir)
- **Doc utilisateur publique** : `packages/core/docs/brain/` (architecture, intégration Module/Domain, API)
- Migration `synapse-doc` sous-agent pour publier la doc Brain v3

## 5. Étapes d'implémentation

À détailler.

## 6. Tests & dogfooding

### Validation qualité synapses

- Métriques cumulées de tous les jalons précédents (régression interdite)
- **Métrique créativité** : ≥1 association non-évidente par session sur corpus weecom, validée manuellement
- Test consolidation : 100 épisodes similaires sur 30 jours → 1 fait sémantique consolidé

## 7. Décisions ouvertes

- ADR-XXX : valeur des paramètres decay par aire (à mesurer empiriquement)
- ADR-XXX : seuil consolidation (combien d'épisodes similaires pour 1 fait sémantique ?)
- ADR-XXX : seuil validation-gated (à partir de quel score auto la synapse est acceptée sans user) ?
- ADR-XXX : ouverture aux apps consommatrices (lycee_intranet, basile) — quelles guides, quelle deprecation policy ?

## 8. Hors-scope

Aucun — c'est le jalon final du chantier Brain v3. Tout ce qui n'est pas fait à la fin du jalon 8 est soit explicitement reporté à un futur chantier, soit documenté comme "non livré, raison X" dans le bilan.

## 9. Bilan

*À remplir à la fin du jalon. Marquera la fin du chantier Brain v3.*

Inclure aussi :
- **Mise à jour de `.evolutions/ROADMAP.md`** : items absorbés/réorientés (cf. roadmap §positionnement)
- **Communication interne** : `packages/core/docs/changelog.md` entrée majeure
- **Plan d'ouverture aux apps consommatrices** (jalon ultérieur, hors Brain v3)
