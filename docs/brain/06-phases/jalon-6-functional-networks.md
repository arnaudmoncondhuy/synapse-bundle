---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 6 — Functional networks (modes contextuels)

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Modes contextuels.** Les mêmes neurones et synapses s'activent différemment selon le contexte de travail actif. Un mode `focus` (entité + temporalité récente) amplifie l'épisodique récent ; un mode `discovery` (large) amplifie l'encyclopédique.

Les noms de modes restent **génériques** (pas de "brief deal", "support ticket", etc.) — un mode est une primitive Brain ; les apps hôtes peuvent en créer des spécialisés via leurs propres `FunctionalNetwork` côté `Module/{Domain}`.

## 2. Test de sortie

```bash
bin/console brain:query --mode=focus "tell me about X"
bin/console brain:query --mode=discovery "tell me about X"
# → top retrieval mesurablement différent entre les 2 modes (jaccard ≤ 0.6)
```

## 3. Pré-requis

- Jalon 5 livré (synapses enrichies)
- Modes opérationnels définis : à lister dans un ADR (au moins 2 modes pour la démo)

## 4. Surface à concevoir

- `Brain\Entity\FunctionalNetwork` (table `brain_functional_network`)
- `Brain\Service\NetworkActivator` — au runtime, applique des modificateurs de poids par aire selon le mode actif
- API `BrainContext::switchMode(string $mode)`
- Tool LLM `BrainSwitchContextTool`

## 5. Étapes d'implémentation

À détailler.

## 6. Tests & dogfooding

### Validation qualité synapses

- Définir 2-3 modes génériques (ex: `focus`, `discovery`, `triage`)
- Pour chaque mode, jeu de 5 requêtes type
- Métrique : **différence Jaccard** entre top-N de 2 modes différents ≥ 0.4 (les modes doivent vraiment changer la sortie)
- Métrique : F1 par mode sur ses requêtes type ≥ baseline mode neutre

## 7. Décisions ouvertes

- ADR-XXX : modes en BDD ou en config YAML ?
- ADR-XXX : mode activé manuellement (user choisit) ou inféré (LLM détecte) ?

## 8. Hors-scope

- SNP webhooks (jalon 7)
- UI graphe (jalon 7)

## 9. Bilan

*À remplir à la fin du jalon.*
