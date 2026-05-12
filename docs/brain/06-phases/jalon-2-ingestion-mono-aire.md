---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 2 — Ingestion mono-aire

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Aucune encore.** Une source brute → un neurone dans une aire choisie (sémantique ou épisodique). C'est le premier service `MemoryExtractor` opérationnel, en version mono-aire.

## 2. Test de sortie

```bash
# Ingestion d'une source weecom (lecture seule) → 1 neurone sémantique
bin/console brain:ingest:test --source-id=<deal_id> --area=semantic
# → produit N neurones sémantiques (1 fait extrait = 1 neurone), tous liés à 1 MemorySource

# Ingestion en mode épisodique
bin/console brain:ingest:test --source-id=<deal_id> --area=episodic
# → produit 1 neurone épisodique (l'événement situé)
```

## 3. Pré-requis

- Jalon 1 livré (entités MemorySource, EpisodicNeuron, SemanticNeuron, Synapse en place)
- ADR à écrire : choix de l'extracteur LLM (prompt + modèle) — déterministe ou non

## 4. Surface à concevoir

- `Brain\Service\MemoryExtractor` (orchestrateur multi-aires, démarre mono-aire ici)
- `Brain\Service\Extractor\SemanticExtractor` — prend une source, retourne `SemanticNeuron[]`
- `Brain\Service\Extractor\EpisodicExtractor` — prend une source, retourne `?EpisodicNeuron`
- `Brain\Entity\EncyclopedicNeuron` (3ème aire, déjà chunkable via le RagManager existant à absorber)
- Migration `SynapseRagDocument` → `EncyclopedicNeuron` (data migration script)
- Lecture corpus weecom : `tools/brain-bench/extract-corpus.php` (lecture seule)

## 5. Étapes d'implémentation

À détailler. Estimation : 7-9 étapes / commits.

## 6. Tests & dogfooding

- Tests unitaires pour chaque Extractor
- Smoke test sur 3 deals weecom (varier les profils : court / long / multi-noter)

## 7. Décisions ouvertes

- ADR-XXX : quel modèle LLM pour extraction (Gemini Flash ? OVH ?) — coût vs qualité
- ADR-XXX : prompt d'extraction versionné dans le code ou en BDD ?

### Décision orientée (pré-tranchée, à confirmer par mesure)

**Stratégie d'appel LLM pour l'extracteur multi-aires : 1 passe unique** (le LLM reçoit la source + prompt décrivant les 7 aires, et retourne `{aire: contenu_extrait}` en sortant lui-même les neurones à créer et leur aire d'affectation).

- **Cohérence avec design §9** : *"Une passe LLM = N neurones créés dans N tables différentes"*
- **Justification philosophique** : le LLM qui voit la source décide où ranger — analogue biologique du stimulus qui active naturellement les bonnes aires. Pas de pré-classifieur séparé qui déciderait sans contexte.
- **Risque identifié** : biais de complétion du schema (LLM remplit des aires non-pertinentes par mimétisme). À neutraliser dans le prompt par instruction explicite : *"tu as le droit / le devoir de laisser des aires vides quand la source ne les concerne pas"*. Sélectivité naturelle (design §38) à mesurer en sortie de jalon 3.

**Rappel transversal (design §10) :** le tri pertinence/non-pertinence se fait à l'usage via les synapses (pruning par seuil de poids, décroissance temporelle, renforcement Hebbien par co-activation), pas à l'extraction. Un neurone créé sans être convoqué finit par disparaître naturellement. L'extracteur n'a donc pas à être parfait — le système s'auto-régule à l'usage.

**Variante écartée :** routeur léger + extracteurs spécialisés par aire (2 étapes). Possiblement meilleure qualité par aire, mais perd la vision globale et complexifie pour un gain non démontré. À reconsidérer si la mesure du jalon 3 montre une dérive qualité.

## 8. Hors-scope

- Multi-aires simultanées (jalon 3)
- Convergence mémorielle (jalon 3)
- Spreading activation (jalon 4)

## 9. Bilan

*À remplir à la fin du jalon.*
