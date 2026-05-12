# Références et inspirations techniques

> Sources externes utilisées comme **inspiration technique** pour Brain v3.
> ⚠️ Ce document n'est pas un comparatif produit. Brain v3 ne se positionne pas sur le marché de l'agent memory ; il vise à associer des idées (cf. [00-charte.md](00-charte.md) §1).

## Statut de vérification

Toutes les références ci-dessous ont été **vérifiées** : URL/arxiv ID confirmés, titre exact, auteurs. Les références issues du tableau §2 du design doc ont été passées au crible (notamment les références Hebbiennes critiques : HeLa-Mem et Kairos). Aucune hallucination détectée.

Date de vérification : 2026-05-12.

## Frameworks OSS / commerciaux de mémoire d'agents

| Nom | Type | URL/repo | Ce qu'on en retient pour Brain v3 |
|---|---|---|---|
| **Mem0** | OSS, framework mémoire universelle | [github.com/mem0ai/mem0](https://github.com/mem0ai/mem0) | Approche vector + units. Bon exemple de "couche mémoire universelle" agnostique. Inspiration pour le pattern `MemorySource` commun. |
| **Letta** (ex-MemGPT) | OSS, agents stateful avec mémoire | [github.com/letta-ai/letta](https://github.com/letta-ai/letta) | 3 tiers OS-like (working / archival / long-term). Inspiration partielle pour la distinction transduction/association. |
| **Zep** | Commercial, plateforme mémoire/contexte | [getzep.com](https://www.getzep.com) | KG temporel avec validity window. Inspiration pour le decay temporel par aire (§8 design). |
| **Cognee** | OSS, memory control plane | [github.com/topoteretes/cognee](https://github.com/topoteretes/cognee) | KG + vector DB combinés. Inspiration pour la coexistence vector/graph dans une seule architecture. |

## Papers académiques

### Papier d'ancrage central : HippoRAG 2

**Arxiv** : [2502.14802](https://arxiv.org/abs/2502.14802) — *From RAG to Memory: Non-Parametric Continual Learning for LLMs* — Jiménez Gutiérrez et al., **ICML 2025**.

Ce qu'on retient :
- Personalized PageRank sur un knowledge graph extrait par LLM
- Continual learning sans fine-tuning, plafonné en raisonnement
- **Limite reconnue par les auteurs** : un seul poids par edge → pas de polarity, pas de relation_type
- Brain v3 dépasse cette limite via les 5 dimensions de synapse (cf. design §6)

### Papiers Hebbiens critiques

#### HeLa-Mem (2026)

**Arxiv** : [2604.16839](https://arxiv.org/abs/2604.16839) — *Hebbian Learning and Associative Memory for LLM Agents* — Zhu et al., **ACL 2026**.

Ce qu'on retient :
- Plasticité Hebbienne formalisée pour LLM agent memory : *neurons that fire together wire together*
- Dynamic graph avec renforcement par co-activation
- **Limite** : decay homogène (un seul taux), pas de polarité
- Brain v3 ajoute : decay configurable par aire (§8 design), polarity excitatory/inhibitory (§6)

#### Kairos (2025/2026)

**OpenReview** : [forum?id=EN9VRTnZbK](https://openreview.net/forum?id=EN9VRTnZbK) — *Validation-Gated Hebbian Learning for Adaptive Agent Memory* — workshop NORA'25 / NeurIPS 2025.

Ce qu'on retient :
- Formalisation **LTP/LTD** (Long-Term Potentiation/Depression) pour mémoire d'agent
- **Validation gate** : consolidation conditionnée à un filtre qualité (évite renforcement d'hallucinations)
- Score auto : `LLM confidence × user rating × historical accuracy`
- Brain v3 étend ce pattern via `ConsolidationGate` (jalon 8, §8 design)

### Référence canonique

**Generative Agents** — [arxiv 2304.03442](https://arxiv.org/abs/2304.03442) — Park, O'Brien, Cai, Morris, Liang, Bernstein (Stanford + Google, 2023).

Ce qu'on retient :
- Scoring **Importance × Recency × Relevance** pour retrieval mémoire
- Reflection step qui consolide observations en abstractions (équivalent épisodique → sémantique)
- Brain v3 reprend l'architecture par couches mais avec spécialisation par aire au lieu de mémoire homogène

## Papers complémentaires utiles

| Référence | Arxiv | Apport pour Brain v3 |
|---|---|---|
| **A-MEM** — *Agentic Memory for LLM Agents* | [2502.12110](https://arxiv.org/abs/2502.12110) | Méthode Zettelkasten avec liens évolutifs. Proche conceptuellement, code OSS disponible. À étudier au jalon 4 (spreading activation). |
| **H-MEM** — *Hierarchical Memory for Long-Term Reasoning* | [2507.22925](https://arxiv.org/abs/2507.22925) | Hiérarchie mémoire pour raisonnement long. Inspiration pour consolidation cross-aires (jalon 8). |
| **FadeMem** — *Biologically-Inspired Forgetting* | [2601.18642](https://arxiv.org/abs/2601.18642) | Courbe d'oubli d'Ebbinghaus comme mécanisme d'éviction. À considérer pour `MemoryDecayService`. |
| **Memory for Autonomous LLM Agents** (survey) | [2603.07670](https://arxiv.org/abs/2603.07670) | Survey récent qui cadre le champ. Point d'entrée bibliographique. |
| **Governing Evolving Memory in LLM Agents (SSGM)** | [2603.11768](https://arxiv.org/abs/2603.11768) | Risques et gouvernance de la mémoire évolutive. **Critique** pour ne pas tomber dans une approche Hebbienne naïve. |

## Méthode d'utilisation des références

Conformément à la charte §2.5 ("Comprendre avant d'intégrer") :

1. **Avant** d'écrire un composant qui ressemble à un paper cité, le lire (au moins l'abstract + sections design)
2. **Avant** d'écrire un composant qui ressemble à un projet OSS cité, lire le README + la zone de code la plus proche
3. **Décider explicitement** : intégrer la lib, ré-implémenter, ou écarter — et documenter dans un ADR (`05-decisions/`)
4. **Ne pas copier-coller** : la métaphore biologique de Brain v3 est opérationnelle, pas scientifique. On ne livre pas un papier, on livre un brain qui marche

## Ce qu'on **n'a pas** mais qu'on pourrait avoir besoin

Domaines où la littérature ne nous couvre pas bien :

- **Polarity excitatory/inhibitory pour LLM memory** — aucune référence dédiée trouvée. C'est une contribution propre à Brain v3 (cf. tableau §2 du design)
- **Functional networks contextuels** — pas de paper dédié. À documenter dans un ADR au jalon 6
- **I/O distincte sensoriel/moteur** — pattern original. À documenter au jalon 7
- **UI graphe de mémoire auditable** — pratique observée chez Zep (KG visualization), pas dans la littérature académique

Ces zones sont précisément celles où Brain v3 explore un terrain peu cartographié — d'où l'importance des phases de validation progressive (cf. [01-roadmap.md](01-roadmap.md)) et de la mesure à chaque jalon.

---

**Suite** :
- Décisions techniques structurantes : [05-decisions/](05-decisions/)
- Plans par jalon : [06-phases/](06-phases/)
