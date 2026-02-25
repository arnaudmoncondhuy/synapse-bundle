# Évolution : Gestion et Sélection Dynamique d'Outils (Tool Retrieval)

Cette proposition vise à doter le bundle Synapse d'un système robuste de gestion d'outils. **En tant que composant réutilisable (Bundle), Synapse doit pouvoir s'adapter à n'importe quelle taille de projet**, depuis un petit POC avec 5 outils jusqu'à un écosystème complexe d'entreprise dépassant les 50 outils. L'enjeu est de gérer cette montée en charge sans saturer le contexte du LLM, augmenter la latence ou dégrader la pertinence des réponses.

## Vision Pragmatique (Approche "Lean")

L'écueil principal à éviter est de construire d'emblée une "usine à gaz". Plutôt que de multiplier les appels LLM lents et coûteux (Routing par LLM) ou d'imposer des infrastructures complexes dès le premier jour, la stratégie repose sur un socle métier déterministe, couplé à une fonctionnalité d'administration puissante, et évoluant progressivement vers une recherche vectorielle locale et rapide.

## Stratégies de Déploiement : Adaptabilité aux tailles de projet

Le bundle proposera plusieurs modes de fonctionnement configurables (via `synapse.yaml`), activables selon la maturité du projet hôte :

1. **Mode "Direct" (Starter / < 15 outils)**
   - **Comportement** : Tous les outils autorisés (après filtrage RBAC) sont envoyés systématiquement au LLM dans le prompt système.
   - **Complexité** : Nulle. Ce mode ne nécessite aucune vectorisation.
   - **Levier de précision** : La "Surcharge Dynamique Globale" (via l'interface d'administration, voir ci-dessous) devient le moyen principal d'affiner la précision du LLM en cas de besoin.

2. **Mode "Vectoriel Local" (Croissance / 15 - 100 outils)**
   - **Comportement** : Seul le Top-K des outils sémantiquement proches (Cosine Similarity) de la requête utilisateur est envoyé au LLM.
   - **Complexité** : Basse. Ce mode repose sur un modèle d'embedding léger et un cache local (JSON/SQLite).

3. **Mode "Vector Store Externe" (Enterprise / 100+ outils)**
   - **Comportement** : Délégation complète de l'indexation et de la recherche à une base de données vectorielle dédiée (Qdrant, Milvus, Pinecone, etc.).
   - **Complexité** : Haute. Conçu pour les architectures microservices nécessitant un connecteur externe.

## Architecture Proposée (Le Coeur du Système)

Peu importe le mode choisi, le processus de sélection complète s'opérera en 3 étapes clés, du plus strict au plus sémantique :

### 1. Filtrage de Sécurité (RBAC via Tags)
- **Principe** : Utiliser les attributs PHP (ex: `#[AsTool(tags: ['admin'])]`) **exclusivement** pour restreindre l'accès à un outil selon les droits de l'utilisateur ou le contexte de l'application.
- **Bénéfice** : Les tags ne servent pas à la sélection sémantique (trop rigide), mais agissent comme un pare-feu de sécurité inviolable avant l'envoi au LLM ou la recherche vectorielle.

### 2. Outils Résidents (Always-On Restrictif)
- **Principe** : Déclarer un nombre très limité (2 à 3 maximum) d'outils prioritaires injectés en permanence.
- **Bénéfice** : Maintenir à disposition des outils vitaux (ex: une recherche de fallback ou l'accès au support) sans polluer la fenêtre de contexte allouée aux outils dynamiques.

### 3. Sélection Vectorielle (Modes 2 & 3 uniquement)
- **Principe** : Utiliser des embeddings pour trouver les outils pertinents.
- **Implémentation Locale ("Lean")** :
  - **Auto-indexation** globale : Une commande Symfony (ex: `php bin/console synapse:tool:index`) scanne les classes PHP, extrait les descriptions, génère les embeddings et les stocke, garantissant une synchronisation parfaite entre le code et l'index vectoriel.

## Gestion des Risques et Solutions d'Administration

L'implémentation devra impérativement traiter les risques inhérents au *Tool Retrieval*, en s'appuyant fortement sur des outils d'administration visuels :

1. **Le Diagnostic et la Surcharge des Descriptions (Plan B Interface)** : Comment identifier rapidement qu'un outil n'est jamais sélectionné, ou comment régler un problème de compréhension du LLM sans toucher au code ?
   - *Prévention* : Capitaliser sur l'interface d'administration existante (`/synapse/admin/tools`).
     - **Surcharge Dynamique Globale des Outils** : L'interface doit permettre de **surcharger complètement la description de l'outil et de ses paramètres**. Que l'on soit en "Mode Direct" ou en "Mode Vectoriel", c'est cette description surchargée (en BDD ou YAML) qui fait autorité. Cela permet au PO (Product Owner) d'ajuster le comportement du LLM face à un outil sans nécessiter de déploiement technique.
     - **Audit Sémantique** : Intégrer un indicateur de "Qualité de la description" basé sur des heuristiques (longueur, mots-clés).

2. **La Perte de Découvrabilité** : Si un utilisateur formule mal sa demande, la similarité cosinus (en mode Vectoriel) risque de masquer des outils pertinents.
   - *Prévention* : Définir un seuil de pertinence (Threshold). Si aucun outil ne correspond, utiliser le principe des "Outils Résidents" pour guider l'utilisateur.

3. **L'Incohérence de Session** : Un outil utilisé au message `N` pourrait disparaître du contexte au message `N+1` lors d'une recherche vectorielle.
   - *Prévention* : Maintenir les outils récemment invoqués dans une fenêtre glissante (historique de session) pour garantir la continuité des tâches complexes.

4. **Le Biais Littéraire** : La sélection dépend de la qualité de description des outils par les développeurs.
   - *Prévention* : Forcer des règles strictes sur la rédaction des docstrings (paramètres inclus). La **Surcharge Dynamique globale** (point 1) agit ici comme la parfaite solution de secours.

## Plan d'Action Itératif (Roadmap)

Le déploiement de cette évolution se fera progressivement pour valider la valeur à chaque étape :

1. **Phase 1 : Socle de Sécurité et Tags (Sans IA)**
   - Ajout des propriétés `tags` et `keywords` à l'attribut `#[AsTool]`.
   - Ajout de la notion `isAlwaysOn` (Outils résidents).
   - Filtrage du `ToolRegistry` par tags selon le contexte logiciel.

2. **Phase 2 : L'Outillage Métier (Admin UI & Surcharge)**
   - Intégration de la **Surcharge Dynamique Globale** des descriptions via l'interface `/synapse/admin/tools`.
   - Ajout d'indicateurs visuels de qualité des descriptions (Audit sémantique paramétrable).

3. **Phase 3 : Moteur Vectoriel Local (MVP)**
   - Implémentation de la commande console `synapse:tool:index` (génération d'embeddings).
   - Intégration d'un client de calcul de Similarité Cosinus avec stockage léger (JSON/SQLite).

4. **Phase 4 : Sandbox UI (Testeur Vectoriel)**
   - Ajout d'une barre de recherche "Simulateur" dans l'administration pour confronter des requêtes fictives aux outils indexés et tester les scores de pertinence en temps réel.

5. **Phase 5 : Modes Avancés et Activation**
   - Mise en place du routing global de configuration (`synapse.yaml` pour lier les Modes : Direct / Local / Externe).
   - Remplacement de l'injection en dur par le Retriever final du bundle.

---
*Document de conception révisé - Objectif : Implémentation itérative, robuste et agnostique à la taille des projets.*
