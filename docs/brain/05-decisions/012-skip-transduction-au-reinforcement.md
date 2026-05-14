---
status: accepté
date: 2026-05-14
jalon: 4
---

# ADR-012 — HebbianReinforcer skip les synapses `edgeType=Transduction`

## Statut

`accepté` — décidé en cours de jalon 4 (étape 11 calibration, fixtures retrieval-v1). Bug latent corrigé avant l'enrichissement du corpus de bench avec des liens structurels métier (note→deal, person→org, etc.).

## Contexte

Le design figé `docs/brain-v3-design.md` §7 distingue 2 régimes d'edge sur les synapses :

| `edgeType` | Sémantique | Plasticité |
|---|---|---|
| **Association** | Edge Hebbien standard entre aires d'association | Active : weight évolue, decay applicable |
| **Transduction** | Câblage fixe pour audit, trace de provenance/causalité | **Aucune plasticité, aucun decay** |

Au jalon 4 initial, seule l'inférence automatique `Synapse::inferEdgeType()` était utilisée — elle attribue `Transduction` uniquement quand au moins une aire est sensorielle/motrice (Sensory/Motor). Comme ces aires sont inactives jalon 4 (prévues jalon 7), **aucune synapse Transduction n'existait dans la BDD** au moment de l'écriture du `HebbianReinforcer`.

Conséquence : `HebbianReinforcer::reinforce()` ne vérifiait pas l'`edgeType` et appelait `setWeight()` sur n'importe quelle synapse.

Au moment de préparer le bench retrieval-v1 (étape 11 jalon 4), on a réalisé qu'il faut créer des synapses Transduction **explicitement** pour représenter les liens structurels métier (note appartient à un deal, person appartient à une organisation, etc.). Le `relationType=Composes` du design §6 cible exactement ce cas.

→ Si on enrichit le corpus sans corriger le `HebbianReinforcer`, le poids des synapses Transduction sera modifié au retrieval. La sémantique "câblage fixe" est cassée par notre propre code.

## Options considérées

### Option A — Skip silencieusement les Transduction dans `reinforce()`
Un `if` au début : `if ($synapse->getEdgeType() === Transduction) return;`. Aucun log, aucun event.

**Avantages** :
- Simple, 1 ligne
- Sémantique propre : "Transduction n'est jamais Hebbien, par définition"
- Pas de pollution log/event

**Inconvénients** :
- Caller doit savoir qu'il ne faut pas appeler `reinforce()` sur Transduction — sinon il aura l'impression qu'il a fait quelque chose
- Mais en pratique, le caller (`MemoryRetriever`) appelle `reinforce()` sur **toutes** les synapses traversées sans distinguer — donc le skip est nécessaire ici, pas chez le caller

### Option B — Throw exception sur Transduction
`if ($synapse->getEdgeType() === Transduction) throw new InvalidArgumentException(...)`.

**Rejeté** : trop disruptif. Le caller (`MemoryRetriever::reinforceTraversedSynapses`) parcourt toutes les synapses du résultat sans distinguer. Forcer le caller à filtrer en amont ajoute du couplage. La sémantique "skip silencieux" est plus claire et plus robuste.

### Option C — Le caller filtre avant d'appeler reinforce
Modifier `MemoryRetriever::reinforceTraversedSynapses` pour skipper les Transduction.

**Rejeté** : le filtrage doit être à **un seul endroit** (`HebbianReinforcer`). Sinon chaque caller doit reproduire la logique. Et si on ajoute un autre caller jalon 5+ (LLM tool `Brain.reinforce()`), il faudra aussi reproduire. C'est exactement l'anti-pattern "responsabilité dispersée".

### Option D — Log warning sur Transduction
`if (Transduction) { logger.warning('Skipping Transduction reinforce'); return; }`.

**Rejeté** : ce n'est pas une anomalie, c'est le comportement attendu. Logger en warning crée du bruit.

## Décision

**Option A retenue** : skip silencieux dans `HebbianReinforcer::reinforce()`. Premier check avant toute logique de mutation.

```php
public function reinforce(Synapse $synapse, string $cause = 'hebbian_co_activation'): void
{
    if (SynapseEdgeType::Transduction === $synapse->getEdgeType()) {
        return;
    }
    // ... saturation soft + setWeight + event ...
}
```

## Conséquences

- **Code** : 1 `if` en haut de `reinforce()`, import `SynapseEdgeType`
- **Tests** : 2 tests dédiés
  - `testSkipsTransductionSynapse` (weight=1.0, vérifie aucun event ni mutation)
  - `testSkipsTransductionEvenWithLowWeight` (weight=0.3, idem)
- **Sémantique préservée** : les synapses Transduction `Composes` (note→deal, person→org) gardent leur weight tel que créé. Brain les traverse au BFS pour remonter le graphe métier, mais ne modifie jamais leur poids.
- **Decay temporel jalon 8+** : devra aussi skip les Transduction (à inscrire dans le ticket consolidation/decay)

## Notes

- Le ADR-009 (saturation soft + events) reste valide pour les synapses `Association`. ADR-012 raffine : ADR-009 s'applique aux Association ; Transduction est hors-scope.
- Aucun changement au design figé `§7` — le code rejoint enfin sa spec.

---

*Bug latent détecté par le user (2026-05-14) lors de la préparation du corpus relationnel pour le bench retrieval-v1. Le pool de sub-agents `brain-adr-conformance` aurait dû flagger ça avant — à ajouter à sa check-list pour les jalons futurs.*
