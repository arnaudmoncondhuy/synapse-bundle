# Roadmap Brain v3

> Vue d'ensemble du chantier. Le détail de chaque jalon est dans [06-phases/](06-phases/).

## Principe d'ordonnancement

Les jalons ne suivent pas l'ordre du design doc (§12, qui est une "liste d'ingrédients"). Ils suivent un ordre **par capacités d'association livrées**, du plus simple à coder/tester vers le plus subtil.

**Critère d'avancement** : un jalon est livré quand on peut **démontrer une nouvelle forme d'association** sur le corpus de test, pas quand le code compile.

## Les 8 jalons

| # | Nom | Capacité d'association livrée | Test de sortie |
|---|---|---|---|
| 1 | **Fondations** | Aucune (infra). On peut écrire 2 neurones et 1 synapse entre eux | Migration Doctrine OK, table `syn_brain_memory_source` + `syn_brain_synapse` + interface `MemoryFragment` posée |
| 2 | **Ingestion mono-aire** | Aucune (encore). Une source brute → un neurone sémantique ou épisodique | `MemoryExtractor` extrait 1 aire depuis 1 corpus weecom donné, neurone persisté |
| 3 | **Ingestion multi-aires** | **Convergence mémorielle** : 2 sources différentes → neurones similaires dans la même aire détectés | 1 source → ≥2 neurones dans ≥2 aires. Mesure : taux de convergence sur corpus weecom |
| 4 | **Retrieval Hebbien simple** | **Retrieval enrichi** par spreading activation à 2-3 sauts | Une requête déclenche neurones candidats + voisins ; injection en contexte LLM mesurablement meilleure qu'un retrieval vectoriel naïf |
| 5 | **Synapses enrichies** | **Contradictions / nuances** via polarity ; **raisonnement typé** via relation_type | Démo : 2 faits contradictoires (ex: "préférence matin" + "en congé") n'activent pas la même réponse selon polarity |
| 6 | **Functional networks** | **Modes contextuels** : les mêmes neurones s'activent différemment selon le contexte | Switch entre 2 modes (ex: "brief" vs "veille") modifie le top-N retrieval mesurablement |
| 7 | **SNP + UI graphe** | **Auditabilité** + I/O distincte | Webhook entrant agnostique → memory_source → neurones ; UI graphe affiche les associations cliquables ; webhook sortant pour `neuron_motor` |
| 8 | **Maturation** | **Créativité / liens non-évidents** mesurés ; consolidation cron ; validation-gated | Cron decay + consolidation actifs ; ≥1 association non-évidente mesurée par session sur corpus weecom ; doc prête pour intégration apps hôtes |

## Vue chronologique

```
Jalon 1 ─┐
         ├─ Squelette en place (infra brain_*, infra synapse poly)
Jalon 2 ─┘

Jalon 3 ─── Mémoire écrite, lisible par aire

Jalon 4 ─── Premier vrai retrieval Hebbien (capacité 1)

Jalon 5 ─── Polarity + relation_type (capacités 2 et 4)
Jalon 6 ─── Functional networks (modulation contextuelle)

Jalon 7 ─── Auditabilité + SNP (laboratoire visible)

Jalon 8 ─── Maturation, capacité 3 (créativité) mesurée, ouverture aux apps
```

## Pas de date

Le chantier n'a pas de deadline. Chaque jalon démarre après validation du précédent et après l'écriture/validation du plan de phase. Le rythme s'adapte à la disponibilité du user et à la difficulté révélée par chaque jalon.

Cela dit : un chantier sans rythme s'enlise. Si **un jalon dépasse 3 sessions actives sans livraison**, on passe en rétrospective (cf. [02-methodologie-sessions.md](02-methodologie-sessions.md#rétro)) — pas pour pousser, mais pour comprendre où on coince.

## Mesurer chaque jalon

À la fin de chaque jalon, on remplit dans le fichier de phase une section "Bilan" qui répond à :

1. **Capacité livrée** : la promesse du jalon est-elle tenue, démontrable sur corpus weecom ?
2. **Coût** : combien de sessions, combien de LOC, combien de migrations
3. **Surprises** : ce qu'on n'avait pas anticipé (positif ou négatif)
4. **Décisions reportées** : ce qui aurait dû être un ADR mais a été tranché sans (à régulariser)
5. **Go / no-go jalon suivant** : le jalon suivant apporte-t-il mesurablement quelque chose au-dessus de l'état courant ? Si non, **on s'arrête**.

## Hors-scope explicite

Choses qui ne sont **pas** dans la roadmap Brain v3 :

- Refonte UI admin (autre chantier) — sauf l'UI "graphe de mémoire" du jalon 7
- Refonte providers LLM / token accounting / spending limits — restent en `syn_core_*` sans changement structurel
- Génération auto d'agents/workflows ([[project-workflow-agent-generation]]) — le brain les nourrira, mais leur génération est un autre chantier
- Migration des autres apps consommatrices (lycee_intranet, basile) — au jalon 8 seulement, et seulement si l'API Brain est stable

## Positionnement vs `.evolutions/ROADMAP.md`

La roadmap produit du bundle ([`.evolutions/ROADMAP.md`](../../.evolutions/ROADMAP.md)) liste des items priorisables par tier (Tier 1 quick wins → Tier 4 vision long terme). Brain v3 est un chantier **transverse breaking** qui touche plusieurs zones de cette roadmap. Conséquences :

| Item de la roadmap produit | Statut vis-à-vis de Brain v3 |
|---|---|
| Tier 3 — **Webhooks événements** (`WebhookTargetInterface`, retry, HMAC) | **Absorbé** par le SNP du jalon 7 (webhook entrant/sortant agnostique) |
| Tier 4 — **Système de plugins** (API publique d'extension) | **Réoriente** : le pattern `Module/{Domain}` + tags DI synapse.* est la surface plugin pour Brain v3 |
| Tier 3 — **PII Detector** | Compatible, à intégrer en phase ENRICH avant `BrainContextSubscriber` |
| Tier 4 — **A/B Testing prompts** | Hors scope mais bénéficiera des functional networks du jalon 6 |
| Tier 1-2 — items non liés (export logs, share link, etc.) | Indépendants, peuvent avancer en parallèle sur `main` |

Une fois Brain v3 mergé (jalon 8), la roadmap produit sera mise à jour pour refléter les items absorbés/réorientés.

---

**Suite** : [02-methodologie-sessions.md](02-methodologie-sessions.md) pour comprendre comment on attaque concrètement chaque jalon.
