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
*   **Monitoring par Module** : Suivi détaillé de l'usage (Chat, Missions, Tâches système).
*   **État des Services** : Liste des providers (Gemini, OpenAI, etc.) actuellement activés.

---

## 💰 Gestion des Quotas (Spending Limits)

Nouveauté majeure de la V2, cette page permet de définir des limites de dépense :
*   **Plafonds par utilisateur** : Bridez la consommation de vos utilisateurs (ex: 5€ / mois).
*   **Plafonds par mission** : Limitez le budget d'un agent spécifique.
*   **Périodes glissantes** : Gestion intelligente des quotas sur 24h ou 30 jours via le cache.


---

## ⚙️ Paramètres Globaux (Settings)
Cette page permet de configurer le comportement par défaut du bundle :
*   **Langue du Contexte** : Définit la langue de l'IA et de l'interface par défaut (via le système de translation `synapse_admin`).
*   **Rétention RGPD** : Nombre de jours avant la purge automatique des messages.
*   **Prompt Système Global** : Instructions de base qui seront ajoutées à toutes les conversations.
*   **Mode Debug** : Active ou désactive le logging technique approfondi.

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
