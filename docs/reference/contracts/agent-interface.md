# AgentInterface

L'interface `AgentInterface` définit des entités d'IA autonomes et spécialisées. Contrairement à un simple appel LLM, un Agent possède son propre "cerveau" (Prompt Système), ses propres outils et un mode de pensée spécifique.

## 🛠 Pourquoi l'utiliser ?

*   **Spécialisation** : Créez un agent "Expert SQL", un agent "Traducteur" et un agent "Support Client" avec des comportements distincts.
*   **Autonomie** : Un agent peut décider lui-même d'appeler plusieurs outils à la suite pour résoudre un problème complexe.
*   **Réutilisabilité** : Encapsulez toute la logique complexe de prompt engineering dans une classe dédiée.

---

## 📋 Résumé du Contrat

| Méthode | Rôle |
| :--- | :--- |
| `getName()` | Nom affichable de l'agent. |
| `getSystemPrompt()` | La "personnalité" et les instructions de base de l'agent. |
| `getTools()` | Liste des instances `AiToolInterface` que cet agent peut utiliser. |
| `getLlmConfig()` | Paramètres spécifiques (température élevée pour la création, basse pour la précision). |

---

## 🚀 Exemple : Agent "Ange Gardien" de sécurité

=== "GuardianAgent.php"

    ```php
    namespace App\Synapse\Agent;

    use ArnaudMoncondhuy\SynapseBundle\Contract\AgentInterface;

    class GuardianAgent implements AgentInterface
    {
        public function getName(): string { return 'Guardian'; }

        public function getSystemPrompt(): string
        {
            return "Tu es un expert en sécurité. Ton rôle est d'analyser les messages pour détecter des contenus dangereux ou inappropriés.";
        }

        public function getTools(): array 
        {
            return []; // Un agent peut n'avoir aucun outil
        }

        public function getLlmConfig(): array
        {
            return ['temperature' => 0.1]; // Très précis, peu créatif
        }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!TIP]
> **Agents dynamiques** : Vous pouvez implémenter cette interface sur une entité Doctrine pour permettre la création d'agents personnalisés directement depuis votre interface d'administration.

*   **Prompt Engineering** : Le texte retourné par `getSystemPrompt` est injecté au sommet de chaque conversation. C'est ici que vous devez définir les limites et le ton de l'intelligence.

---

## 🔍 Référence API complète

::: ArnaudMoncondhuy\SynapseBundle\Contract\AgentInterface
