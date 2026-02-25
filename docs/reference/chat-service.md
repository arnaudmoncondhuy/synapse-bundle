# ChatService

Le `ChatService` est le point d'entrée principal de SynapseBundle. C'est l'orchestrateur qui gère la communication avec le LLM, la gestion du contexte, l'appel des outils et le streaming.

## 🛠 Pourquoi l'utiliser ?

*   **Simplicité** : Envoyez un message et recevez une réponse IA avec une seule ligne de code.
*   **Orchestration automatique** : Il gère pour vous le cycle de vie des promps, les itérations d'outils et le stockage des messages.
*   **Performance** : Supporte nativement le streaming pour une expérience utilisateur fluide.

---

## 📋 Méthodes principales

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `ask(string $message, array $options)` | Message brut | `string` | Déclenche un échange complet avec le LLM. |

---

## 🚀 Utilisation classique

Voici comment utiliser le service dans un contrôleur Symfony.

=== "ChatController.php"

    ```php
    namespace App\Controller;

    use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;
    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
    use Symfony\Component\HttpFoundation\Response;

    class ChatController extends AbstractController
    {
        public function index(ChatService $chatService): Response
        {
            $response = $chatService->ask("Bonjour, peux-tu m'aider ?", [
                'model' => 'gemini-1.5-flash',
                'temperature' => 0.7,
                'stream' => false
            ]);

            return new Response($response);
        }
    }
    ```

---

## ⚙️ Options disponibles

La méthode `ask()` accepte un tableau d'options pour personnaliser l'échange :

*   **`model`** : Identifiant technique du modèle LLM à utiliser.
*   **`temperature`** : (float) Entre 0.0 et 1.0 (créativité).
*   **`stream`** : (bool) Si vrai, le service émettra des événements pour chaque token reçu.
*   **`max_output_tokens`** : Limite la longueur de la réponse.
*   **`debug`** : (bool) Active le logging détaillé de l'échange.

---

## 💡 Conseils d'utilisation

> [!TIP]
> **Streaming** : Pour utiliser le streaming, passez `stream: true` et écoutez l'événement `SynapseChunkReceivedEvent`. Cela permet d'afficher la réponse au fur et à mesure qu'elle arrive, comme sur ChatGPT.

---

## 🔍 Référence API complète

::: ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService
