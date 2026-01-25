# 🔌 Guide des Interactions - SynapseBundle

Ce document recense de manière exhaustive toutes les méthodes d'interaction disponibles avec `SynapseBundle`. Il sert de référence pour l'intégration backend, frontend, et l'extension des fonctionnalités.

---

## 🛠️ 1. Configuration
Le bundle se configure via le fichier `config/packages/synapse.yaml`.

### Options disponibles

| Clé | Type | Défaut | Description |
| :--- | :--- | :--- | :--- |
| `model` | string | `gemini-2.5-flash-lite` | Identifiant du modèle Google Gemini à utiliser pour la génération. |
| `personas_path` | string | `null` | Chemin absolu vers un fichier JSON contenant les définitions de personnalités. Si null, utilise le fichier interne du bundle. |

**Note CRITIQUE sur la Clé API :**
Le Bundle **ne stocke pas** et **ne lit pas** la clé API depuis vos variables d'environnement (`.env`) pour les requêtes HTTP.
Pour chaque appel au endpoint `/synapse/api/chat`, le client (Frontend/App) **DOIT OBLIGATOIREMENT** fournir la clé dans le payload JSON (`api_key`).
Le service PHP `ChatService`, s'il est utilisé manuellement, doit aussi recevoir cette clé via `$options['api_key']`.

---

## 🧠 2. Services PHP (Backend API)
Ces services sont disponibles dans le conteneur de services de Symfony et peuvent être injectés dans vos contrôleurs ou services.

### `ArnaudMoncondhuy\SynapseBundle\Service\ChatService`
Le cœur du réacteur. Gère l'orchestration des échanges, l'historique et les outils.

#### Méthode : `ask(string $message, array $options = [], ?callable $onStatusUpdate = null)`
Envoie un message à l'IA et récupère la réponse.

*   **$message** `string`: Le message de l'utilisateur.
*   **$options** `array`:
    *   `api_key` (string, **Requis**): La clé API Gemini.
    *   `model` (string, Optional): Surcharge le modèle configuré globalement.
    *   `stateless` (bool, Défaut: `false`): Si `true`, ne charge ni ne sauvegarde l'historique (mode "one-shot").
    *   `reset_conversation` (bool, Défaut: `false`): Efface l'historique AVANT de traiter ce message.
    *   `persona` (string, Optional): Clé de la personnalité à utiliser pour cet échange.
    *   `tools` (array, Optional): Liste de définitions d'outils spécifiques (écrase les outils globaux).
    *   `debug` (bool, Défaut: `false`): Active la collecte de logs détaillés.
*   **$onStatusUpdate** `callable`: Fonction de callback pour le feedback temps réel (`function(string $msg, string $step): void`).

#### Méthode : `resetConversation()`
Vide l'historique de la conversation courante (via le `ConversationHandler` actif).

#### Méthode : `getConversationHistory()`
Retourne un tableau brut de tous les messages de la session actuelle.

### `ArnaudMoncondhuy\SynapseBundle\Service\PersonaRegistry`
Gère les personnalités chargées depuis le fichier JSON.

#### Méthode : `getAll()`
Retourne un tableau associatif de toutes les personas disponibles `['key' => PersonaObject]`.

#### Méthode : `get(string $key)`
Retourne l'objet `Persona` correspondant à la clé ou null.

---

## 🎨 3. Frontend (Twig)
Fonctions disponibles directement dans vos templates `.html.twig`.

### `{{ synapse_chat_widget() }}`
Affiche le composant de chat complet. Il inclut automatiquement le HTML, le CSS nécessaire et initialise le contrôleur Stimulus pour la logique JS.
*   **Usage :** Placez-le simplement où vous voulez que le chat apparaisse.

### `{{ synapse_get_personas() }}`
Retourne la liste des personas.
*   **Usage :** Utile pour construire un menu déroulant ou une interface de sélection de personnalité personnalisée.
    ```twig
    {% for key, persona in synapse_get_personas() %}
        <button data-key="{{ key }}">{{ persona.name }}</button>
    {% endfor %}
    ```

---

## 🧩 4. Extension & Customisation
Pour étendre les capacités du bundle, implémentez ces interfaces. Le bundle détecte automatiquement vos classes grâce à l'autoconfiguration (tags `synapse.*`).

### `ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface`
Implémentez cette interface pour créer un **Outil (Function Calling)** que l'IA peut utiliser.
*   **Méthodes clés :** `getName()`, `getDescription()`, `getInputSchema()`, `execute()`.
*   **Tag auto :** `synapse.tool`

### `ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface`
Implémentez cette interface pour injecter du contexte dynamique (Date, User, Env) ou modifier le System Prompt global.
*   **Méthodes clés :** `getSystemPrompt()`, `getInitialContext()`.
*   **Tag auto :** `synapse.context_provider`

### `ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface`
Implémentez cette interface pour changer le mode de stockage de l'historique (ex: Base de données au lieu de Session Symfony).
*   **Méthodes clés :** `loadHistory()`, `saveHistory()`, `clearHistory()`.
*   **Tag auto :** `synapse.conversation_handler`

---

## 🌐 5. API HTTP
Endpoints exposés par le bundle, utilisables par des applications tierces ou des frontends JS découplés (React/Vue/Mobile).

### POST `/synapse/api/chat`
Endpoint principal de conversation.
*   **Content-Type :** `application/json`
*   **Payload :**
    ```json
    {
      "message": "Bonjour",
      "api_key": "votre-cle-api-si-necessaire",
      "model": "gemini-pro-vision",
      "options": {
        "persona": "expert_tech",
        "debug": true
      }
    }
    ```
*   **Réponse :** Flux `application/x-ndjson` (Newlines Delimited JSON). Chaque ligne est un événement (`status`, `result`, `error`).

### POST `/synapse/api/reset`
Réinitialise la conversation côté serveur.
*   **Réponse :** `{"success": true, "message": "Conversation reset."}`

### GET `/synapse/_debug/{id}`
Affiche une page HTML de débogage pour une interaction spécifique (si `debug: true` était activé).
*   **Accès :** Nécessite l'ID de debug retourné dans la réponse de l'API `/chat`.
