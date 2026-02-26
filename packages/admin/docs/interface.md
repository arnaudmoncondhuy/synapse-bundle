# Interface d'Administration

Synapse Admin inclut une interface d'administration complète pour piloter votre IA en temps réel, sans toucher au code.

## 🚀 Accès
Par défaut, l'interface est accessible via l'URL : `/synapse/admin`.

> [!IMPORTANT]
> **Sécurité** : L'accès est protégé par la configuration `synapse.admin_role` (par défaut `ROLE_ADMIN`). Assurez-vous d'avoir ce rôle pour accéder aux pages.

---

## 📊 Tableau de Bord (Dashboard)
Le point central pour monitorer la santé de votre système IA :
*   **KPIs Temps Réel** : Nombre de conversations actives sur les dernières 24h.
*   **Statistiques de Consommation** : Suivi précis du nombre de tokens utilisés et estimation du coût financier sur 7 jours.
*   **Graphiques de Tendance** : Visualisation de l'usage quotidien sur 30 jours.
*   **État des Services** : Liste des providers (Gemini, OpenAI, etc.) actuellement activés.

---

## ⚙️ Paramètres Globaux (Settings)
Cette page permet de configurer le comportement par défaut du bundle :
*   **Langue du Contexte** : Définit la langue dans laquelle l'IA doit s'exprimer par défaut.
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
