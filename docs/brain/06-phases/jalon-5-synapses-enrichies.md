---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 5 — Synapses enrichies (polarity + relation_type + confidence)

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Contradictions / nuances** via polarity excitatory/inhibitory + **raisonnement typé** via relation_type (9 valeurs). Le brain peut distinguer "ces 2 faits se renforcent" de "ces 2 faits se contredisent".

## 2. Test de sortie

```bash
# Démo polarity
bin/console brain:demo:polarity
# Cas : "X préfère le matin" + "X est en congé cette semaine"
# → polarity INHIBITORY entre "RDV proposable matin" et "en congé"
# → retrieval pour "proposer RDV X" filtre correctement (pas de matin cette semaine)

# Démo relation_type
bin/console brain:demo:relation-type
# Cas dogfooding weecom : "facture impayée" → "relance automatique déclenchée"
# → synapse CAUSAL, pas CORROBORATES
```

## 3. Pré-requis

- Jalon 4 livré (spreading activation fonctionnel)
- Extracteur LLM mis à jour pour produire polarity + relation_type au moment de la création de synapse

## 4. Surface à concevoir

- `Brain\Service\SynapseEnricher` — analyse 2 neurones, propose polarity + relation_type + confidence
- Update extracteur pour produire des synapses pré-enrichies à l'ingestion (mode D du design §10)
- Tool LLM `BrainLinkTool` étendu pour permettre au LLM de proposer polarity + type (mode B)
- MemoryRetriever : prise en compte de la polarity dans le scoring (excitatory amplifie, inhibitory pénalise)

## 5. Étapes d'implémentation

À détailler.

## 6. Tests & dogfooding

### Tests PHPUnit
- `SynapseEnricherTest` : cas connus de contradictions / corroborations
- Tests des 9 valeurs de relation_type

### Validation qualité synapses

- Fixtures de contradictions versionnées : 20 paires de faits annotées (10 contradictoires, 10 corroborantes)
- Métrique : **précision polarity** ≥ 0.7
- **Précision relation_type** ≥ 0.6 sur fixtures annotées
- Bench dégradation : vérifier que F1 retrieval (jalon 4) ne dégrade pas

## 7. Décisions ouvertes

- ADR-XXX : polarity automatique par LLM ou seulement quand le LLM la déclare explicitement ?
- ADR-XXX : relation_type "GENERIC" est-il un cop-out qu'on doit interdire au-delà d'un % ?

## 8. Hors-scope

- Functional networks (jalon 6)
- Decay differentiel par polarity (jalon 8)

## 9. Bilan

*À remplir à la fin du jalon.*
