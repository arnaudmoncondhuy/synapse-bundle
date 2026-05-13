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

## Papers 2024-2026 directement pertinents pour le retrieval graphique (passe 2)

Revus en 2026-05-13 par revue ciblée (3 agents en parallèle). Cf. ADR-008 passe 2.

| Référence | Arxiv | Apport pour Brain v3 |
|---|---|---|
| **SA-RAG** — *Spreading Activation RAG* | [2512.15922](https://arxiv.org/abs/2512.15922) | **Notre approche ré-actualisée 2026.** Paramètres : depth 3-4, threshold τₐ=0.5, seeds k=3-10. MuSiQue 67-87% (avec CoT). Référence directe pour calibration. |
| **CatRAG** — critique du PPR statique | [2602.01965](https://arxiv.org/abs/2602.01965) | "Static Graph Fallacy" : transition matrix fixée ignore le query context, random walks dérivent vers les hubs. Source de notre **`hubFactor`** explicite (cf. ADR-008 passe 2). |
| **EcphoryRAG** | [2510.08958](https://arxiv.org/abs/2510.08958) | **Depth=2 optimal documenté** (plateau au-delà). EM 0.722 HotpotQA. Validation de la réduction `HARD_MAX_DEPTH` 5→3. |
| **LiCoMemory** — CogniGraph | [2511.01448](https://arxiv.org/abs/2511.01448) | Graph hiérarchique léger + reranking. **LongMemEval 73.8% acc / 76.6% recall**. Cible métrique pour bench retrieval. |
| **HippoRAG (v1)** | [2405.14831](https://arxiv.org/abs/2405.14831) | Référence Personalized PageRank. Damping=0.5 (vs 0.85 web PageRank). |
| **PathRAG** | [2502.14902](https://arxiv.org/abs/2502.14902) | Flow-based pruning. Alternative au BFS pour gros graphes. À considérer jalon 5+. |
| **Mem0 / Mem0g** | [2504.19413](https://arxiv.org/abs/2504.19413) | Production reference. LoCoMo +5-11% vs baselines, latence p95 −91%. |
| **GraphRAG (Microsoft)** | [2404.16130](https://arxiv.org/abs/2404.16130) | Community detection multi-niveaux. Coût ~331k tokens/query Global — **contre-exemple** (trop cher pour Brain conversationnel). |
| **LightRAG** | [2410.05779](https://arxiv.org/abs/2410.05779) | Dual-level keywords (low + high). Pattern de seed extraction enrichi à considérer jalon 5+. |
| **GraphReader (Tencent)** | [2406.14550](https://arxiv.org/abs/2406.14550) | Agent LLM qui navigue le graphe via read_node/read_neighbor. Pattern alternatif au BFS, **hors-scope Brain** (Brain reste déterministe). |
| **MemGPT** | [2310.08560](https://arxiv.org/abs/2310.08560) | Tiered memory + function calling OS-like. Référence pour l'expose éventuelle de tools `brain:*` (jalon 7+). |
| **End-to-End Memory Networks** | [1503.08895](https://arxiv.org/abs/1503.08895) | Référence historique du multi-hop différentiable. Confirme empiriquement la saturation à 3-4 hops. |
| **Memory Layers at Scale (Meta)** | [2412.09764](https://arxiv.org/abs/2412.09764) | Product-key memories à 1B+ params. Pas notre approche mais utile pour scale. |
| **When to use Graphs in RAG** (benchmark ICLR 2026) | [2506.05690](https://arxiv.org/abs/2506.05690) | Cadre d'évaluation pour décider quand un retrieval graphique aide vs vector simple. |
| **Memory in the age of AI agents** (survey 2025) | [2512.13564](https://arxiv.org/abs/2512.13564) | Taxonomie 3-lens (forms × functions × dynamics). |
| **Graph agent memory survey** | [2602.05665](https://arxiv.org/abs/2602.05665) | Taxonomie graph-memory spécifique. |
| **MemoryAgentBench (ICLR 2026)** | (benchmark) | 4 compétences cognitives : retention, update, retrieval, conflict resolution. Cible bench standard. |

## Fondations classiques (passe 2)

Revus pour ne pas réinventer mal des choses déjà étudiées en 1949-2020.

| Référence | Lien | Apport |
|---|---|---|
| **Hebb (1949)** original | [Wikipedia](https://en.wikipedia.org/wiki/Hebbian_theory) | Δw = η·x·y. Instabilité prouvée sans saturation. Notre `w + δ(1-w)` y répond. |
| **Règle d'Oja (1982)** | [Wikipedia](https://en.wikipedia.org/wiki/Oja%27s_rule) | Saturation par compétition (norme=1). **≠ notre saturation per-edge.** Assumé. |
| **BCM (1982)** Bienenstock-Cooper-Munro | [Wikipedia](https://en.wikipedia.org/wiki/BCM_theory) | Seuil de modification glissant + LTP/LTD. Mécanisme d'**affaiblissement actif** manquant chez nous — critique jalon 5+ (consolidation). |
| **Hopfield (1982)** | [Wikipedia](https://en.wikipedia.org/wiki/Hopfield_network) | Capacité critique 0.138N. Notre graphe n'est pas un Hopfield (pas d'attracteurs) mais avertissement à connaître pour N grand. |
| **Collins & Loftus (1975)** spreading activation | [Anderson 1983 PDF](http://act-r.psy.cmu.edu/wordpress/wp-content/uploads/2012/12/66SATh.JRA.JVL.1983.pdf) | Origine du spreading activation. Decay par hop typique 0.5-0.8. Notre 0.7 dans la zone. |
| **ACT-R (Anderson 1983)** | [Anderson Unit 5](http://act-r.psy.cmu.edu/wordpress/wp-content/themes/ACT-R/tutorials/unit5.htm) | Latency factor F ≈ 0.63, decay power-law t^-0.5 (pas exponentiel). Notre exponentiel est défendable mais pas l'orthodoxie ACT-R. |
| **STDP (Bi & Poo 1998)** | [Scholarpedia](http://www.scholarpedia.org/article/Spike-timing_dependent_plasticity) | Asymétrie LTP/LTD à la milliseconde. **Secondaire** pour mémoire sémantique LLM. |
| **Ebbinghaus forgetting curve** | [PMC](https://pmc.ncbi.nlm.nih.gov/articles/PMC4492928/) | Power law vs exponentiel débat ouvert (Wixted 2007). Notre 0.995/jour défendable pour système avec réactivation. |
| **Preferential attachment** (rich-get-richer) | [Wikipedia](https://en.wikipedia.org/wiki/Preferential_attachment) | Phénomène universel des graphes. À surveiller : notre saturation plafonne UN poids, pas le DEGRÉ d'un neurone. |

## Frameworks OSS analysés (lecture de code, passe 2)

| Framework | URL | Retrieval réel (code lu) |
|---|---|---|
| **mem0** | [github.com/mem0ai/mem0](https://github.com/mem0ai/mem0) | Single-hop vector + BM25 hybrid. **Anti-hub : `1/(1 + 0.001·(degree-1)²)`** — formule reprise par Brain v3. |
| **Letta** (ex-MemGPT) | [github.com/letta-ai/letta](https://github.com/letta-ai/letta) | Single-hop vector + BM25 fusionnés via RRF (k=60). **Pas de graphe.** |
| **Cognee** | [github.com/topoteretes/cognee](https://github.com/topoteretes/cognee) | Graph triplet retrieval + k-hop neighborhood optionnel. Pas d'anti-hub. |
| **A-MEM** | [github.com/agiresearch/A-mem](https://github.com/agiresearch/A-mem) | Vector + 1-hop link traversal (top-k voisins). Pas d'anti-hub. **Intelligence à l'écriture, pas à la lecture.** |
| **HippoRAG** | [github.com/OSU-NLP-Group/HippoRAG](https://github.com/OSU-NLP-Group/HippoRAG) | Personalized PageRank, damping=0.5. Anti-hub par division par fréquence de mention. |
| **LightRAG** | [github.com/HKUDS/LightRAG](https://github.com/HKUDS/LightRAG) | Single-hop dual-level keywords + edges triés par (degré DESC, poids DESC). **Inverse anti-hub** : priorité aux hubs. |

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
