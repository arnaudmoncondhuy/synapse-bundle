---
status: accepté
date: 2026-05-13
jalon: 4
---

# ADR-007 — Profondeur BFS du spreading activation : 2 sauts par défaut

## Statut

`accepté` — décidé pendant l'affinage du plan jalon 4. Configurable au runtime par `RetrievalQuery::$maxDepth`.

## Contexte

Le `SpreadingActivation` du jalon 4 propage depuis les neurones seeds vers leurs voisins via les synapses. À quelle profondeur s'arrêter ?

- 1 saut : seul les voisins directs. Conservateur, prévisible
- 2 sauts : voisins des voisins. Permet des associations indirectes (A→B→C)
- 3 sauts ou plus : explosion combinatoire potentielle, risque de bruit

Pour 20 sources × 50 synapses moyennes (estimation jalon 3), à 2 sauts on touche typiquement 100-500 neurones. À 3 sauts, 500-5000 selon la densité du graphe — déjà difficile à scorer raisonnablement en PHP en temps réel.

## Options considérées

### Option A — 1 saut (conservateur)

Pas vraiment du "spreading activation" : juste un retrieval enrichi par voisinage direct.

**Rejeté** : trop limité. Le design §10 mentionne "2-3 sauts max" explicitement.

### Option B — 2 sauts (équilibré)

Voisins + voisins-des-voisins. Permet les associations indirectes courantes :
- "facturation imprimante" → SemanticNeuron(facturation) → Synapse → SemanticNeuron(imprimante) → Synapse → EpisodicNeuron(RDV imprimante du 12/03)
- Couvre 80%+ des cas d'usage prévus au jalon 4

**Retenu**.

### Option C — 3 sauts par défaut

Plus profond, plus de bruit.

**Reporté** : à reconsidérer si le bench retrieval montre que 2 sauts ne suffit pas. Pour l'instant, on commence conservateur — si on a besoin de plus, on augmente.

## Décision

**2 sauts par défaut**, **configurable** au runtime via `RetrievalQuery::$maxDepth`.

Justification :

1. **Cohérence avec design §10** : *"2-3 sauts max"* — 2 est la borne basse, prudent pour démarrer
2. **Perf prévisible** : croissance polynomiale bornée (n × moyenne_synapse_degree² ≈ 500 ops par requête)
3. **Configurable** : l'app hôte peut augmenter à 3 si la BDD est petite ou si on cherche des associations lointaines (mode "explorer")
4. **Mesurable** : le bench retrieval (étape 11) comparera 2 vs 3 sauts sur fixtures, on aura les chiffres

Implementation :

```php
final readonly class RetrievalQuery
{
    public function __construct(
        // ... autres
        public int $maxDepth = 2,  // ADR-007 — 2 sauts par défaut
    ) {}
}

final readonly class SpreadingActivation
{
    // Borne dure : 5 sauts max même si query demande plus (sécurité perf)
    private const HARD_MAX_DEPTH = 5;

    public function expand(RetrievalQuery $query, array $seeds): array
    {
        $effectiveDepth = min($query->maxDepth, self::HARD_MAX_DEPTH);
        // ... BFS jusqu'à $effectiveDepth
    }
}
```

## Conséquences

- **Code** : `RetrievalQuery::$maxDepth = 2` par défaut. `SpreadingActivation` borne dur à 5 (sécurité)
- **Tests** : `SpreadingActivationTest` doit couvrir maxDepth=1, 2, 3 sur graphe synthétique
- **Documentation** : Command `brain:query --depth=N` permet de tester ad-hoc
- **ADRs à suivre** :
  - Après bench (étape 11), si 2 sauts est insuffisant → nouvel ADR ou amendement
  - Au jalon 6 (functional networks), la profondeur pourra être pondérée selon le mode actif

## Notes

- L'option Plan mode était recommandée pour ce jalon dans le plan jalon 3 (squelette). Préférée par pragmatisme : décision orientée par défaut + mesure ultérieure
- La borne dure `HARD_MAX_DEPTH = 5` protège contre les abus côté apps hôtes (un user peut envoyer maxDepth=1000, on cap)

---

*Décidé au démarrage du jalon 4 (2026-05-13).*
