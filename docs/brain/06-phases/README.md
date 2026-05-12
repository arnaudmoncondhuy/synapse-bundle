# Plans de phase — Brain v3

> Un fichier par jalon. Chaque plan est **rédigé en détail juste avant d'attaquer le jalon**, validé par le user, puis exécuté.

## Index

| # | Jalon | Statut | Capacité d'association livrée |
|---|---|---|---|
| 1 | [Fondations](jalon-1-fondations.md) | à valider | Aucune (infra) |
| 2 | [Ingestion mono-aire](jalon-2-ingestion-mono-aire.md) | squelette | Aucune (encore) |
| 3 | [Ingestion multi-aires](jalon-3-ingestion-multi-aires.md) | squelette | Convergence mémorielle |
| 4 | [Retrieval Hebbien simple](jalon-4-retrieval-hebbien.md) | squelette | Retrieval enrichi (spreading activation) |
| 5 | [Synapses enrichies](jalon-5-synapses-enrichies.md) | squelette | Contradictions / nuances + raisonnement typé |
| 6 | [Functional networks](jalon-6-functional-networks.md) | squelette | Modes contextuels |
| 7 | [SNP + UI graphe](jalon-7-snp-ui-graphe.md) | squelette | Auditabilité + I/O distincte |
| 8 | [Maturation](jalon-8-maturation.md) | squelette | Créativité mesurée, consolidation, validation-gated |

**Statuts possibles** :
- `squelette` — sections obligatoires en place, contenu à affiner avant exécution
- `à valider` — plan détaillé, en attente de validation user
- `en cours` — un jalon ne peut être "en cours" qu'à la fois
- `livré` — bilan rempli, capacité démontrée
- `bloqué` — rétrospective déclenchée (>3 sessions sans livraison, cf. méthodo)

## Structure obligatoire d'un plan

Cf. [02-methodologie-sessions.md](../02-methodologie-sessions.md#plan-de-phase--structure-obligatoire) :

1. Capacité d'association visée
2. Test de sortie
3. Pré-requis (jalons + ADRs)
4. Surface à concevoir
5. Étapes d'implémentation
6. Tests & dogfooding (avec section **Validation qualité synapses** dès jalon 3)
7. Décisions ouvertes (→ ADRs)
8. Hors-scope du jalon
9. Bilan (rempli à la fin)
