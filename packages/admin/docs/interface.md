# Interface d'Administration

Synapse Admin inclut une interface d'administration complète pour piloter votre IA en temps réel, sans toucher au code.

## 🚀 Accès
Par défaut, l'interface est accessible via l'URL : `/synapse/admin`.

> [!IMPORTANT]
> **Sécurité** : L'accès est protégé par la configuration `synapse.admin_role` (par défaut `ROLE_ADMIN`). Assurez-vous d'avoir ce rôle pour accéder aux pages.

---

## 📊 Tableau de Bord (Dashboard)

Le Dashboard de l'**Administration Synapse** est le point central pour monitorer votre système :
*   **Analytics de Consommation** : Vue d'ensemble des tokens utilisés et des coûts associés par jour/mois.
*   **Monitoring par Module** : Suivi détaillé de l'usage (Chat, Agents, Tâches système).
*   **État des Services** : Liste des providers (Gemini, OpenAI, etc.) actuellement activés.

---

## 💰 Gestion des Quotas (Spending Limits)

Nouveauté majeure de la V2, cette page permet de définir des limites de dépense :
*   **Plafonds par utilisateur** : Bridez la consommation de vos utilisateurs (ex: 5€ / mois).
*   **Plafonds par agent** : Limitez le budget d'un agent spécifique.
*   **Périodes glissantes** : Gestion intelligente des quotas sur 24h ou 30 jours via le cache.


---

## ⚙️ Paramètres Globaux (Settings)
Cette page permet de configurer le comportement par défaut du bundle :
*   **Langue du Contexte** : Définit la langue de l'IA et de l'interface par défaut (via le système de translation `synapse_admin`).
*   **Rétention RGPD** : Nombre de jours avant la purge automatique des messages.
*   **Prompt Système Global** : Instructions de base qui seront ajoutées à toutes les conversations.
*   **Mode Debug** : Active ou désactive le logging technique approfondi.

---

## 🤖 Agents

Configuration des agents IA spécialisés. Chaque agent combine :

*   **System Prompt** : Instructions de rôle et comportement
*   **Preset LLM** (optionnel) : Modèle, température, autres paramètres
*   **Ton de réponse** (optionnel) : Style de communication (zen, efficace, poétique, etc.)
*   **Outils autorisés** : Sélection des outils (`AiToolInterface`) accessibles par cet agent (NULL = tous)
*   **Sources RAG autorisées** : Sélection des sources de connaissance spécifiques
*   **Contrôle d'accès** : Rôles Symfony ou identifiants utilisateur autorisés (NULL = public)

**Gestion** :
*   **Liste** : Affiche tous les agents (builtins + custom)
*   **Création/Édition** : Formulaire complet de configuration
*   **Outils visibles** : Badge affichant le nombre d'outils assignés
*   **Accès restreint** : Indicateur "🔒" pour les agents avec contrôle d'accès

> [!NOTE]
> Reportez-vous à [Contrôle d'accès aux agents](../../core/docs/agent-access-control.md) pour la configuration complète des permissions.

---

## 📚 Sources RAG (Retrieval-Augmented Generation)

Gestion des sources de documents indexés pour enrichir les réponses de l'IA.

*   **Liste des sources** : Affiche chaque source avec :
    - Nombre de documents indexés
    - Date de la dernière réindexation
    - Statut (`pending`, `indexing`, `done`, `failed`)
    - Bouton de réindexation
*   **Création/Édition** : Paramétrage du provider (Google Drive, Notion, fichiers locaux, etc.)
*   **Visualisation des chunks** : Tableau des documents indexés avec :
    - Contenu du chunk
    - Métadonnées (fichier source, page, etc.)
    - Score de pertinence (pour tests de requête)
*   **Réindexation** : Lance `synapse:rag:reindex` asynchrone (Messenger)
    - Affiche la progression : fichiers traités / fichiers totaux
    - Affiche les erreurs en cas d'échec

> [!TIP]
> Utilisez `php bin/console synapse:rag:test "ma requête"` pour tester la pertinence des résultats RAG avant déploiement.

---

## 🚨 Alertes de Dépenses

Surveillance des dépassements de limite de dépense (`SynapseSpendingLimit`).

*   **Tableau des dépassements récents** : Historique des fois où un utilisateur/agent/preset a dépassé son plafond
*   **Colonnes** :
    - Utilisateur affecté
    - Type de limite (par utilisateur / par agent / par preset)
    - Période (jour calendaire / mois / jour glissant / mois glissant)
    - Montant du plafond
    - Dépassement (montant en trop)
    - Date/heure du dépassement
*   **Actions** : Voir le détail d'un dépassement, ajuster le plafond

---

## 🔧 Gestion des Outils (Tools)
Visualisez tous les outils (`AiToolInterface`) enregistrés dans votre application :
*   **Inventaire** : Liste exhaustive des outils disponibles pour le LLM.
*   **Inspection de Schéma** : Vérifiez les paramètres JSON que l'IA doit fournir pour chaque outil.
*   **Documentation** : Affiche les descriptions servies au modèle.

---

## 🤖 Modèles & Fournisseurs
*   **Providers** : Activez ou désactivez vos comptes (OpenAI, Gemini, OvhAi, etc.) et configurez vos clés API de manière sécurisée.
*   **Modèles** : Choisissez vos modèles préférés, gérez leurs capacités (vision, outils) et paramétrez leur tarification pour le calcul des coûts.

---

## 📝 Personas & Presets
Créez des "Personnalités" préconfigurées pour vos utilisateurs :
*   **Presets** : Définissez un modèle, une température et un prompt système spécifique pour un usage donné (ex: "Expert SQL", "Copywriter Email").
*   **Tests** : Un simulateur de chat intégré permet de tester vos presets en direct avant de les déployer.

---

## 🔍 Logs de Debug
Si le mode debug est activé, vous pouvez inspecter chaque échange technique :
*   **Payloads API** : Voir exactement le JSON envoyé et reçu.
*   **Flux d'Événements** : Comprendre quel événement a été déclenché et à quel moment.
*   **Diagnostics** : Identifier rapidement pourquoi un outil n'a pas été appelé comme prévu.
